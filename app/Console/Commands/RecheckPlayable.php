<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-resolve each imported title of a source and set is_published by ACTUAL playability: a title that
 * resolves to a real proxyable stream (hls/mp4) is published; one that resolves to an embed-only player
 * (abyss/hydrax) or nothing (dead/decoy) is unpublished.
 *
 * Built for 9nung, which is MIXED — ~15% of movies + its series carry a clean fembed→vdohls HLS, the
 * rest are abyss ad-traps. Blanket-hiding the source hid the good ones too; this promotes exactly the
 * playable titles. Most-viewed first; gentle (per-title sleep) so we don't get throttled.
 *
 *   php artisan netwix:recheck-playable 9nung --limit=4000 --sleep=300
 */
class RecheckPlayable extends Command
{
    protected $signature = 'netwix:recheck-playable {source} {--limit=100000} {--sleep=300 : ms between titles}';

    protected $description = 'Publish a source\'s titles that resolve to a real stream (hls/mp4), unpublish embed-only/dead ones';

    public function handle(SourceRegistry $registry): int
    {
        $sid = (string) $this->argument('source');
        $source = $registry->get($sid);
        if (! $source) {
            $this->error("Unknown source [{$sid}].");

            return self::FAILURE;
        }

        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;
        $ids = Content::where('source', $sid)->orderByDesc('views')
            ->limit((int) $this->option('limit'))->pluck('id');
        $total = $ids->count();
        $this->info("Rechecking playability of {$total} {$sid} titles…");

        $pub = 0;
        $hid = 0;
        $done = 0;
        foreach ($ids as $id) {
            $ct = Content::find($id);
            if (! $ct) {
                continue;
            }
            $done++;

            // Resolve the way playback actually does — via the first episode's ref. For a series that
            // ref is the /episodes/{slug}-SxE/ page (the tvshows detail page carries no player); for a
            // movie it's the movie path (== source_key). Falls back to source_key when there's no episode.
            $ref = (string) ($ct->episodes()->orderBy('sort')->orderBy('number')->value('source_ref') ?: $ct->source_key);
            try {
                $stream = $source->resolveByRef((string) $ct->source_key, $ref);
            } catch (Throwable $e) {
                $stream = null;
            }
            $playable = $stream && in_array($stream->kind, [RemoteStream::KIND_HLS, RemoteStream::KIND_MP4], true);

            if ((bool) $ct->is_published !== $playable) {
                $ct->forceFill(['is_published' => $playable])->save();
            }
            $playable ? $pub++ : $hid++;

            if ($done % 100 === 0) {
                $this->line("… {$done}/{$total} · playable {$pub} · hidden {$hid}");
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info("Done: {$pub} playable → published, {$hid} unplayable → hidden.");

        return self::SUCCESS;
    }
}
