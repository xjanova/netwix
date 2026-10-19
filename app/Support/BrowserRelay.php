<?php

namespace App\Support;

use App\Models\Episode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** On-demand RongYok work pulled by the owner's BrainX Chrome worker. */
final class BrowserRelay
{
    public const LEASE_SECONDS = 120;

    /** A viewer is waiting on this exact episode. */
    public const PRIORITY_VIEWER = 0;

    /** The next episodes of a title a viewer is already watching through the relay. */
    public const PRIORITY_PREFETCH = 1;

    public const PREFETCH_AHEAD = 2;

    /** Max episode links the worker may hand back besides the one it was asked for. */
    public const MAX_EXTRAS = 3;

    private const HEARTBEAT_KEY = 'browser-relay:heartbeat';

    public static function enabled(): bool
    {
        return (bool) config('services.rongyok.browser_relay_enabled', false)
            && strlen((string) config('services.rongyok.browser_relay_token', '')) >= 32;
    }

    /**
     * A claim within the last 3 minutes. Without a polling worker, queueing would only make viewers
     * wait. Wide enough that a worker with every tab busy (up to ~90s per episode) still counts.
     */
    public static function online(): bool
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);

        return self::enabled() && is_int($beat) && $beat >= now()->timestamp - 180;
    }

    /**
     * The RongYok link of an episode, whether RongYok is the title's own source or one of its
     * mirrors/backups ([MirrorRotation::chain]) — every RongYok title, not only RongYok imports.
     */
    public static function descriptor(Episode $ep): ?array
    {
        if (! self::enabled() || ! $ep->content) {
            return null;
        }
        foreach (MirrorRotation::chain($ep) as $link) {
            if ($link->source === 'rongyok' && preg_match('/^\d{1,20}$/D', $link->key) && preg_match('/^[1-9]\d{0,3}$/D', $link->ref)) {
                return ['source' => 'rongyok', 'series_id' => $link->key, 'episode' => $link->ref];
            }
        }

        return null;
    }

    /** Work for this episode is queued or being opened right now — polling should not re-walk the rotation. */
    public static function inFlight(Episode $ep): bool
    {
        return self::online() && DB::table('browser_relay_jobs')->where('episode_id', $ep->id)
            ->whereIn('state', ['queued', 'running'])->where('requested_at', '>=', now()->subMinutes(2))->exists();
    }

    /** Called only after playback authorization and a miss from the normal source rotation. */
    public static function enqueue(Episode $ep, int $priority = self::PRIORITY_VIEWER): ?array
    {
        $d = self::online() ? self::descriptor($ep) : null;
        if (! $d) {
            return null;
        }
        DB::table('browser_relay_jobs')->insertOrIgnore([
            'episode_id' => $ep->id, 'series_id' => $d['series_id'], 'episode' => $d['episode'], 'priority' => $priority,
            'state' => 'queued', 'attempts' => 0, 'available_at' => now(), 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = DB::transaction(function () use ($ep, $d, $priority): array {
            $job = DB::table('browser_relay_jobs')->where('episode_id', $ep->id)->lockForUpdate()->first();
            // A worker may have completed between the resolver's cache miss and this lock.
            if (RongYokClientResolver::cached($d['series_id'], $d['episode'])) {
                return ['state' => 'pending', 'retry_after' => 1];
            }
            // A viewer asking for a prefetched episode moves it to the front of the queue.
            $update = ['requested_at' => now(), 'updated_at' => now(), 'priority' => min((int) $job->priority, $priority)];
            // A completed URL was evicted/expired, or an exhausted episode has cooled down.
            // Don't touch a live lease, even when many viewers ask for the same episode.
            if ($job->series_id !== $d['series_id'] || $job->episode !== $d['episode'] || $job->state === 'ready'
                || ($job->state === 'failed' && $job->available_at <= now()->toDateTimeString())) {
                $update = ['state' => 'queued', 'attempts' => 0, 'lease_token' => null, 'priority' => $priority,
                    'lease_expires_at' => null, 'available_at' => now(), 'last_error' => null,
                    'series_id' => $d['series_id'], 'episode' => $d['episode']] + $update;
            }
            DB::table('browser_relay_jobs')->where('id', $job->id)->update($update);

            return ['state' => ($update['state'] ?? $job->state) === 'failed' ? 'retry_later' : 'pending', 'retry_after' => 3];
        });

        if ($priority === self::PRIORITY_VIEWER) {
            self::prefetchAfter($ep);
        }

        return $result;
    }

    /**
     * A viewer watching through the relay will most likely press "next": have the following
     * episodes opened while this one plays, so the next one starts without a wait. Bounded to
     * PREFETCH_AHEAD episodes per title per minute — never a catalogue crawl.
     */
    public static function prefetchAfter(Episode $ep): void
    {
        if (! self::online() || ! Cache::add('browser-relay:prefetch:'.$ep->id, 1, 60)) {
            return;
        }
        $next = Episode::query()->where('content_id', $ep->content_id)->where('number', '>', $ep->number)
            ->where(fn ($q) => $q->whereNull('video_url')->orWhere('video_url', ''))
            ->orderBy('number')->limit(self::PREFETCH_AHEAD)->get();
        foreach ($next as $episode) {
            $episode->setRelation('content', $ep->content);
            $d = self::descriptor($episode);
            if ($d && ! RongYokClientResolver::cached($d['series_id'], $d['episode'])) {
                self::enqueue($episode, self::PRIORITY_PREFETCH);
            }
        }
    }

    /** Atomic lease: several workers cannot open the same queued episode concurrently. */
    public static function claim(): ?array
    {
        Cache::put(self::HEARTBEAT_KEY, now()->timestamp, 300);

        // Skip past rows that turn out to be dead or already satisfied instead of idling the worker.
        for ($i = 0; $i < 5; $i++) {
            $result = DB::transaction(function () {
                // Expired jobs remain dormant until a viewer actually asks again.
                $job = DB::table('browser_relay_jobs')
                    ->where('requested_at', '>=', now()->subMinutes(10))
                    ->where(function ($q) {
                        $q->where(fn ($q) => $q->where('state', 'queued')->where('available_at', '<=', now()))
                            ->orWhere(fn ($q) => $q->where('state', 'running')->where('lease_expires_at', '<=', now()));
                    })
                    ->orderBy('priority')->orderBy('requested_at')->orderBy('id')->lockForUpdate()->first();
                if (! $job) {
                    return null;
                }
                $ep = Episode::with('content')->find($job->episode_id);
                $d = $ep && $ep->content?->is_published ? self::descriptor($ep) : null;
                $live = $d && $d['series_id'] === $job->series_id && $d['episode'] === $job->episode;
                if (! $live || $job->attempts >= 3) {
                    self::failRow($job, $live ? 'lease_expired' : 'content_unavailable', true);

                    return false;
                }
                // Another job's page already handed this link back.
                if (RongYokClientResolver::cached($job->series_id, $job->episode)) {
                    self::markReady($job->id);

                    return false;
                }
                $token = bin2hex(random_bytes(32));
                DB::table('browser_relay_jobs')->where('id', $job->id)->update([
                    'state' => 'running', 'attempts' => $job->attempts + 1, 'lease_token' => hash('sha256', $token),
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now(),
                ]);

                return ['id' => $job->id, 'lease_token' => $token, 'lease_seconds' => self::LEASE_SECONDS,
                    'source' => 'rongyok', 'series_id' => $job->series_id, 'episode' => $job->episode,
                    'priority' => (int) $job->priority];
            });
            if ($result !== false) {
                return $result;
            }
        }

        return null;
    }

    private static function leased(int $id, string $token): object
    {
        $job = DB::table('browser_relay_jobs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($job && $job->state === 'running' && $job->lease_expires_at > now()->toDateTimeString()
            && hash_equals((string) $job->lease_token, hash('sha256', $token)), 409, 'Relay lease expired or replaced');

        return $job;
    }

    /**
     * @param  array<int, array{episode: string, video_url: string}>  $extras  other episodes of the same
     *                                                                         series the episode page fetched
     * @return int|false number of extra episode links accepted, or false when the requested link is unusable
     */
    public static function complete(int $id, string $token, string $url, array $extras = []): int|false
    {
        $job = DB::transaction(function () use ($id, $token, $url): ?object {
            $job = self::leased($id, $token);
            if (! RongYokClientResolver::accept(['series_id' => $job->series_id, 'episode' => $job->episode], $url)) {
                return null;
            }
            self::markReady($id);

            return $job;
        });
        if (! $job) {
            return false;
        }

        $accepted = 0;
        foreach (array_slice($extras, 0, self::MAX_EXTRAS) as $extra) {
            $episode = (string) ($extra['episode'] ?? '');
            if ($episode === $job->episode || ! preg_match('/^[1-9]\d{0,3}$/D', $episode)
                || RongYokClientResolver::cached($job->series_id, $episode)) {
                continue;
            }
            if (RongYokClientResolver::accept(['series_id' => $job->series_id, 'episode' => $episode], (string) $extra['video_url'])) {
                $accepted++;
                DB::table('browser_relay_jobs')->where('series_id', $job->series_id)->where('episode', $episode)
                    ->where('state', 'queued')->update(['state' => 'ready', 'last_error' => null, 'updated_at' => now()]);
            }
        }

        return $accepted;
    }

    public static function fail(int $id, string $token, string $error): void
    {
        DB::transaction(function () use ($id, $token, $error): void {
            self::failRow(self::leased($id, $token), $error);
        });
    }

    private static function markReady(int $id): void
    {
        DB::table('browser_relay_jobs')->where('id', $id)->update([
            'state' => 'ready', 'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null, 'updated_at' => now(),
        ]);
    }

    private static function failRow(object $job, string $error, bool $terminal = false): void
    {
        $terminal = $terminal || $job->attempts >= 3;
        DB::table('browser_relay_jobs')->where('id', $job->id)->update([
            'state' => $terminal ? 'failed' : 'queued', 'last_error' => $error,
            // A waiting viewer retries quickly; background prefetch backs off.
            'available_at' => now()->addSeconds($terminal ? 600
                : ((int) ($job->priority ?? 0) === self::PRIORITY_VIEWER ? 5 : 30) * max(1, $job->attempts)),
            'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
        ]);
    }
}
