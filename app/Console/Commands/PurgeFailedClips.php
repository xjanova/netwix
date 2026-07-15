<?php

namespace App\Console\Commands;

use App\Models\MarketingClip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete marketing-clip rows that never produced a usable clip — a cut that FAILED (dead/rotated
 * source, ffmpeg error) or a job that died and left the row stuck pending/processing — so they stop
 * cluttering the admin history (owner: "ที่สร้างไม่ได้ ให้ลบทิ้ง วันต่อวัน").
 *
 * Unlike netwix:clips:purge-files (which keeps posted rows as history and only drops their heavy
 * files), this removes the ROW entirely: a failed clip has no caption / post / file worth keeping.
 * Safe by construction:
 *   - failed rows are terminal; deleted once older than --days (0 = clear everything now).
 *   - pending/processing rows are only swept once STUCK (older than 6h) — far beyond the clips
 *     queue's 310s / heavy lane's 90-min ceiling — so an in-flight or freshly-queued cut is never
 *     touched, whatever --days is.
 *   - campaign slots are unaffected: clip_campaign_posts.marketing_clip_id is nullOnDelete, and the
 *     runner's dedup never counted failed clips.
 * Runs daily from the scheduler; also usable by hand with --days / --dry-run.
 */
class PurgeFailedClips extends Command
{
    protected $signature = 'netwix:clips:purge-failed
        {--days=1 : delete FAILED clips older than this many days (0 = all)}
        {--dry-run : show what would be deleted without deleting}';

    protected $description = 'Delete failed/stuck marketing-clip rows so they stop cluttering the history.';

    /** A pending/processing row older than this is a dead job, never an in-flight cut. */
    private const STUCK_HOURS = 6;

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $failedCutoff = now()->subDays($days);
        $stuckCutoff = now()->subHours(self::STUCK_HOURS);

        $clips = MarketingClip::where(function ($q) use ($failedCutoff, $stuckCutoff) {
            $q->where(fn ($w) => $w->where('status', 'failed')->where('created_at', '<', $failedCutoff))
                ->orWhere(fn ($w) => $w->whereIn('status', ['pending', 'processing'])->where('created_at', '<', $stuckCutoff));
        })->get();

        if ($clips->isEmpty()) {
            $this->info("nothing to purge (failed clips older than {$days} day(s), or jobs stuck > ".self::STUCK_HOURS.'h).');

            return self::SUCCESS;
        }

        $disk = Storage::disk('public');
        $files = 0;
        $counts = [];
        foreach ($clips as $clip) {
            // A failed clip usually has no files, but a job can die after writing a partial
            // poster/mp4 — remove those too so nothing is orphaned on disk.
            foreach (array_filter([$clip->file_path, $clip->poster_path]) as $path) {
                try {
                    if ($disk->exists($path)) {
                        $files++;
                        $dry || $disk->delete($path);
                    }
                } catch (Throwable $e) {
                    // best-effort file cleanup
                }
            }
            $counts[$clip->status] = ($counts[$clip->status] ?? 0) + 1;
            $dry || $clip->delete();
        }

        $breakdown = collect($counts)->map(fn ($n, $s) => "{$s}={$n}")->implode(' ');
        $this->info(($dry ? '[dry-run] would delete ' : 'deleted ')
            ."{$clips->count()} clip rows ({$breakdown}) + {$files} orphan files.");

        return self::SUCCESS;
    }
}
