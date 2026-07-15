<?php

namespace App\Console\Commands;

use App\Models\MarketingClip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete the mp4 + poster of marketing clips older than the retention window, but KEEP the row
 * (owner: "เก็บคลิปไว้ตรวจสอบ 15 วันพอ แล้วลบอัตโนมัติ แต่ประวัติเก็บไว้").
 *
 * A clip's heavy files are only needed briefly — to review it and to let Facebook fetch it at
 * post time. After 15 days the files are pure dead weight on a shared box, so they are purged;
 * the row stays with its caption, posted_at, remote_post_id and title, so the history + the
 * admin log remain complete. Runs daily from the scheduler; also usable by hand with --days /
 * --dry-run.
 */
class PurgeClipFiles extends Command
{
    protected $signature = 'netwix:clips:purge-files
        {--days=15 : delete files of clips older than this many days}
        {--dry-run : list what would be purged without deleting}';

    protected $description = 'Delete old marketing-clip mp4/poster files (keeps the rows as history).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // Age from the post time when known (a clip lives its useful life once posted), else from
        // creation. Only touch clips that still HAVE files and were not purged already.
        $clips = MarketingClip::whereNull('files_purged_at')
            ->where(fn ($q) => $q->whereNotNull('file_path')->orWhereNotNull('poster_path'))
            ->whereRaw('COALESCE(posted_at, created_at) < ?', [$cutoff])
            ->get();

        if ($clips->isEmpty()) {
            $this->info("nothing to purge (older than {$days} days).");

            return self::SUCCESS;
        }

        $disk = Storage::disk('public');
        $bytes = 0;
        $files = 0;
        foreach ($clips as $clip) {
            foreach (array_filter([$clip->file_path, $clip->poster_path]) as $path) {
                if ($disk->exists($path)) {
                    $bytes += (int) $disk->size($path);
                    $files++;
                    if (! $dry) {
                        $disk->delete($path);
                    }
                }
            }
            if (! $dry) {
                // Row stays; just forget where the (now-deleted) files were + stamp the purge.
                $clip->forceFill([
                    'file_path' => null,
                    'poster_path' => null,
                    'files_purged_at' => now(),
                ])->saveQuietly();
            }
        }

        $mb = round($bytes / 1048576, 1);
        $this->info(($dry ? '[dry-run] would purge ' : 'purged ')
            ."{$files} files ({$mb} MB) from {$clips->count()} clips older than {$days} days.");

        return self::SUCCESS;
    }
}
