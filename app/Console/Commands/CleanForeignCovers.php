<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\ForeignWatermark;
use App\Support\PosterBackfill;
use App\Support\PosterSearch;
use App\Support\SiteSearchScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replaces covers that carry another site's watermark with clean artwork.
 *
 *   php artisan netwix:clean-covers --dry-run          # report only, change nothing
 *   php artisan netwix:clean-covers --limit=100        # do a batch
 *   php artisan netwix:clean-covers --source=goseries4k
 *
 * The order matters: a marked cover has to be REPLACED, not painted over. Stamping our own logo on
 * top of goseries4k's would leave the poster wearing two brands, which is worse than either alone.
 *
 * Resumable by construction — a title that gets a clean cover no longer trips the detector, and one
 * that could not be fixed is flagged into the missing-covers queue and skipped from then on. So the
 * command can be run repeatedly and each run only does what is left, which is what a shared box
 * needs: this machine has already fallen over once under stacked image work.
 *
 * Nothing is ever deleted without a replacement in hand. If no clean cover can be found, the existing
 * one stays on the page — a watermarked poster still beats an empty slot — and it goes to the admin
 * queue at /admin/covers so a person can upload one.
 */
class CleanForeignCovers extends Command
{
    protected $signature = 'netwix:clean-covers
        {--source=goseries4k : which import source to sweep}
        {--limit=100 : titles to examine this run}
        {--sleep=120 : ms between titles, to stay polite to the sources we search}
        {--dry-run : report what would happen, change nothing}';

    protected $description = 'Find covers carrying another site\'s watermark and replace them with clean artwork';

    public function handle(PosterSearch $search, PosterBackfill $backfill): int
    {
        $source = (string) $this->option('source');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dry = (bool) $this->option('dry-run');

        // Already-flagged titles are known problems waiting on a human — don't re-examine them.
        $titles = Content::withoutGlobalScopes()
            ->where('source', $source)
            ->where('poster_path', 'like', 'media/posters/%')
            ->whereNull('cover_missing_at')
            ->orderByDesc('views')
            ->limit($limit)
            ->get(['id', 'title', 'source', 'source_key', 'poster_path', 'backdrop_path']);

        $this->info(sprintf('ตรวจ %d เรื่องจาก %s%s', $titles->count(), $source, $dry ? ' (ทดลอง — ไม่แก้อะไร)' : ''));

        $marked = 0;
        $replaced = 0;
        $queued = 0;

        foreach ($titles as $content) {
            $file = storage_path('app/public/'.$content->poster_path);
            if (! ForeignWatermark::detected($file)) {
                continue;
            }
            $marked++;
            $this->line(sprintf('  พบลายน้ำ: [%d] %s', $content->id, mb_substr($content->title, 0, 46)));

            if ($dry) {
                continue;
            }

            $clean = $this->findCleanCover($content, $search, $backfill);

            if ($clean !== null) {
                $backfill->apply($content, $clean);
                // The replacement is unmarked, so let the branding sweep stamp it again.
                DB::table('contents')->where('id', $content->id)->update(['poster_watermarked_at' => null]);
                $replaced++;
                $this->info('    → เปลี่ยนปกใหม่แล้ว');
            } else {
                // Keep the marked cover on the page — better than an empty slot — but put the title
                // in front of an admin.
                DB::table('contents')->where('id', $content->id)->update(['cover_missing_at' => now()]);
                $queued++;
                $this->warn('    → หาปกสะอาดไม่ได้ ส่งเข้าคิว /admin/covers ให้อัปโหลดเอง');
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info(sprintf('สรุป: พบลายน้ำ %d · เปลี่ยนปกได้ %d · ส่งเข้าคิว %d', $marked, $replaced, $queued));

        if (! $dry && $titles->count() === $limit) {
            $this->comment('ยังเหลืออีก — รันคำสั่งเดิมซ้ำเพื่อทำต่อ');
        }

        return self::SUCCESS;
    }

    /**
     * Find a clean cover for this title from a DIFFERENT site, and store it. Returns the stored path
     * or null.
     *
     * Only a confident name match is accepted ([PosterSearch::MIN_SCORE] is 0.85), and any candidate
     * that turns out to carry a watermark itself is rejected — otherwise this would happily trade
     * goseries4k's brand for someone else's.
     */
    private function findCleanCover(Content $content, PosterSearch $search, PosterBackfill $backfill): ?string
    {
        foreach ($search->find($content, 6) as $candidate) {
            if ($candidate->source === $content->source) {
                continue;   // the same site will just hand back the same marked artwork
            }

            $path = $backfill->storeFrom($content, SiteSearchScraper::downloadOrder($candidate->image));
            if ($path === null) {
                continue;
            }

            if (ForeignWatermark::detected(storage_path('app/public/'.$path))) {
                @unlink(storage_path('app/public/'.$path));

                continue;
            }

            return $path;
        }

        return null;
    }
}
