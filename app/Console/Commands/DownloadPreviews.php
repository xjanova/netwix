<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Import\SourceRegistry;
use App\Services\PreviewDownloader;
use Illuminate\Console\Command;

/**
 * Backfills episode-1 preview files for imported titles that don't have one yet. Safe to run on a
 * cron — it only picks up titles still missing their ep 1, downloads a bounded batch, and stops.
 */
class DownloadPreviews extends Command
{
    protected $signature = 'netwix:previews {--limit=20 : max titles to fetch this run} {--source= : only this source}';

    protected $description = 'Download episode 1 of imported titles as a local preview (instant first play + hover)';

    public function handle(PreviewDownloader $downloader, SourceRegistry $registry): int
    {
        // Only progressive-MP4 sources get a cached preview; HLS sources (wow-drama) stream through
        // the server proxy and would just be resolved-then-skipped every run.
        $progressive = collect($registry->all())->filter(fn ($s) => $s->isProgressive())->keys()->all();

        $titles = Content::query()
            ->whereIn('source', $progressive)
            ->whereNotNull('source_key')
            ->when($this->option('source'), fn ($w) => $w->where('source', $this->option('source')))
            ->whereHas('episodes', fn ($e) => $e->where('number', 1)->whereNull('video_url'))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $done = 0;
        $bytes = 0;
        foreach ($titles as $content) {
            try {
                $size = $downloader->downloadFirstEpisode($content);
            } catch (\Throwable $e) {
                $this->warn("✗ {$content->title} — {$e->getMessage()}");
                continue;
            }

            if ($size) {
                $done++;
                $bytes += $size;
                $this->line("✓ {$content->title} (".round($size / 1e6, 1)." MB)");
            } else {
                $this->line("· skip {$content->title}");
            }
        }

        $this->info("previews: {$done}/{$titles->count()} downloaded, ".round($bytes / 1e6, 1)." MB");

        return self::SUCCESS;
    }
}
