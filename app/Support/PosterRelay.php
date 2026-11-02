<?php

namespace App\Support;

use App\Models\SourceTitle;
use App\Models\User;
use App\Models\Content;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Fixed series-page artwork jobs for the existing authenticated Chrome worker. */
final class PosterRelay
{
    public static function cached(string $url): ?string
    {
        $title = SourceTitle::where('source', 'rongyok')->where('poster_url', $url)->first();
        $path = $title?->extra['relay_poster_path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, 'media/source-posters/')
            || ($title->extra['relay_poster_url'] ?? null) !== $url || !Storage::disk('public')->exists($path)) {
            return null;
        }
        $bytes = Storage::disk('public')->get($path);

        return AdvertCover::looksLikeAdvert($bytes, $url) ? null : $bytes;
    }

    public static function enqueue(SourceTitle $title, User $admin): bool
    {
        if ($admin->role !== 'admin' || $title->source !== 'rongyok' || !preg_match('/^\d{1,20}$/D', $title->source_key)
            || !BrowserRelay::online()) {
            return false;
        }
        DB::table('browser_relay_posters')->insertOrIgnore([
            'source_title_id' => $title->id, 'series_id' => $title->source_key, 'requested_by' => $admin->id,
            'state' => 'queued', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('browser_relay_posters')->where('source_title_id', $title->id)
            ->where(fn ($q) => $q->where('state', 'ready')->orWhere(fn ($x) => $x->where('state', 'failed')->where('available_at', '<=', now())))
            ->update(['state' => 'queued', 'attempts' => 0, 'series_id' => $title->source_key, 'requested_by' => $admin->id, 'updated_at' => now()]);

        return true;
    }

    public static function claim(): ?array
    {
        DB::table('browser_relay_posters')->where('state', 'running')->where('attempts', '>=', 3)
            ->where('lease_expires_at', '<=', now())->update(['state' => 'failed', 'available_at' => now()->addMinutes(10)]);
        return DB::transaction(function () {
            $job = DB::table('browser_relay_posters')->where('attempts', '<', 3)
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('state', 'queued')->where('available_at', '<=', now()))
                    ->orWhere(fn ($x) => $x->where('state', 'running')->where('lease_expires_at', '<=', now())))
                ->oldest('id')->lockForUpdate()->first();
            if (!$job) {
                return null;
            }
            $title = SourceTitle::find($job->source_title_id);
            if (!$title || $title->source !== 'rongyok' || $title->source_key !== $job->series_id || !preg_match('/^\d{1,20}$/D', $title->source_key)
                || !User::whereKey($job->requested_by)->where('role', 'admin')->exists()) {
                DB::table('browser_relay_posters')->where('id', $job->id)->update(['state' => 'failed', 'last_error' => 'unavailable']);
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('browser_relay_posters')->where('id', $job->id)->update([
                'state' => 'running', 'attempts' => $job->attempts + 1, 'lease_token' => hash('sha256', $token),
                'lease_expires_at' => now()->addSeconds(120), 'updated_at' => now(),
            ]);

            return ['id' => $job->id, 'type' => 'poster', 'source' => 'rongyok', 'series_id' => $title->source_key,
                'episode' => '1', 'lease_token' => $token, 'lease_seconds' => 120, 'title' => mb_substr($title->displayTitle(), 0, 120),
                'reason' => 'poster', 'requester' => ['member' => true, 'via' => 'admin']];
        });
    }

    private static function leased(int $id, string $token): object
    {
        $job = DB::table('browser_relay_posters')->where('id', $id)->lockForUpdate()->first();
        abort_unless($job && $job->state === 'running' && $job->lease_expires_at > now()->toDateTimeString()
            && hash_equals((string) $job->lease_token, hash('sha256', $token)), 409);

        return $job;
    }

    public static function complete(int $id, string $token, string $data, string $url, int $episodes, ?string $description = null): bool
    {
        return DB::transaction(function () use ($id, $token, $data, $url, $episodes, $description) {
            $job = self::leased($id, $token);
            $title = SourceTitle::findOrFail($job->source_title_id);
            abort_unless($title->source === 'rongyok' && $title->source_key === $job->series_id && $episodes >= 1 && $episodes <= 1000, 409);
            abort_unless(User::whereKey($job->requested_by)->where('role', 'admin')->exists(), 409);
            $bytes = ImageStore::decodeDataUrl($data, 4_000_000);
            $size = $bytes ? @getimagesizefromstring($bytes) : false;
            if (!preg_match('~^https://rongyok\.com/images/poster/[^?#]+$~D', $url) || !$size
                || $size[0] < 150 || $size[1] < 200 || $size[0] * $size[1] > 20_000_000
                || AdvertCover::looksLikeAdvert($bytes, $url)) {
                return false;
            }
            $path = ImageStore::putWebp($bytes, 'media/source-posters', $title->id.'-'.substr(hash('sha256', $bytes), 0, 12), 1200);
            if (!$path) {
                return false;
            }
            $title->forceFill(['extra' => array_merge((array) $title->extra, [
                'relay_poster_path' => $path, 'relay_poster_url' => $title->poster_url,
                'relay_poster_page' => 'https://rongyok.com/series/'.$title->source_key.'/',
                'relay_episodes_count' => $episodes, 'relay_metadata_at' => now()->toIso8601String(),
            ])])->save();
            if (mb_strlen(trim((string) $description)) >= 20) {
                $title->forceFill(['description' => mb_substr(trim(strip_tags($description)), 0, 2000)])->save();
            }
            $content = Content::withoutGlobalScopes()->where('source', $title->source)->where('source_key', $title->source_key)->first();
            if ($content && (!$content->poster_path || AdvertCover::isAdvertHotlink($content->poster_path))) {
                $stored = ImageStore::putCover($bytes, 'media/posters', (string) $content->id, $content->poster_path,
                    PosterBackfill::COVER_MAX_DIM, PosterBackfill::COVER_QUALITY);
                if ($stored) {
                    app(PosterBackfill::class)->apply($content, $stored);
                    PosterBackfill::alignBackdrop($content);
                }
            }
            DB::table('browser_relay_posters')->where('id', $id)->update([
                'state' => 'ready', 'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null, 'updated_at' => now(),
            ]);

            return true;
        });
    }

    public static function fail(int $id, string $token, string $error): void
    {
        DB::transaction(function () use ($id, $token, $error) {
            $job = self::leased($id, $token);
            DB::table('browser_relay_posters')->where('id', $id)->update([
                'state' => $job->attempts >= 3 ? 'failed' : 'queued', 'available_at' => now()->addMinutes($job->attempts >= 3 ? 10 : 1),
                'lease_token' => null, 'lease_expires_at' => null, 'last_error' => $error, 'updated_at' => now(),
            ]);
        });
    }
}
