<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\ForeignWatermark;
use App\Support\ImageStore;
use App\Support\PosterBackfill;
use App\Support\PosterWatermark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Brands the back catalogue with our own mark.
 *
 *   php artisan netwix:watermark-covers --dry-run
 *   php artisan netwix:watermark-covers --limit=500
 *
 * New covers are branded on the way in ([ImageStore::putCover]); this is for the ~23,600 already on
 * disk. It runs in bounded batches and remembers progress in `contents.poster_watermarked_at`,
 * because this box has fallen over once under stacked image work and a single 23,600-file pass is
 * exactly that shape of job.
 *
 * Two things it refuses to do:
 *  - stamp a cover that already carries ANOTHER site's watermark, which would leave the poster
 *    wearing two brands. Those belong to `netwix:clean-covers` first.
 *  - run at all while the feature is switched off, so nobody brands the catalogue by accident.
 *
 * Re-encoding is lossy, so each cover is only ever marked ONCE — the flag is what guarantees it, and
 * it is cleared whenever the cover is replaced so a fresh poster gets branded again.
 */
class WatermarkCovers extends Command
{
    protected $signature = 'netwix:watermark-covers
        {--limit=500 : covers to brand this run}
        {--source= : limit to one import source}
        {--sleep=15 : ms between covers, to keep CPU off the floor}
        {--dry-run : report what would happen, change nothing}';

    protected $description = 'Burn the NetWix mark into covers that do not have it yet';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! PosterWatermark::enabled() && ! $dry) {
            $this->error('ลายน้ำยังปิดอยู่ — เปิดที่ /admin/watermark ก่อน (หรือใช้ --dry-run เพื่อดูเฉย ๆ)');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $cfg = PosterWatermark::config();

        $titles = Content::withoutGlobalScopes()
            ->where('poster_path', 'like', 'media/posters/%')
            ->whereNull('poster_watermarked_at')
            ->when($this->option('source'), fn ($q) => $q->where('source', $this->option('source')))
            ->orderByDesc('views')
            ->limit($limit)
            ->get(['id', 'title', 'source', 'poster_path', 'backdrop_path']);

        $remaining = Content::withoutGlobalScopes()
            ->where('poster_path', 'like', 'media/posters/%')
            ->whereNull('poster_watermarked_at')
            ->count();

        $this->info(sprintf('ใส่ลายน้ำ %d ใบ (เหลือทั้งหมด %s)%s',
            $titles->count(), number_format($remaining), $dry ? ' — ทดลอง ไม่แก้ไฟล์' : ''));

        $done = 0;
        $skippedForeign = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($titles->count());
        $bar->start();

        foreach ($titles as $content) {
            $bar->advance();
            $rel = (string) $content->poster_path;
            $abs = storage_path('app/public/'.$rel);

            // Never stack our mark on top of someone else's.
            if (ForeignWatermark::detected($abs)) {
                $skippedForeign++;

                continue;
            }

            if ($dry) {
                $done++;

                continue;
            }

            $bytes = @file_get_contents($abs);
            $img = $bytes ? @imagecreatefromstring($bytes) : false;
            if ($img === false) {
                $failed++;

                continue;
            }

            if (! PosterWatermark::apply($img, $cfg)) {
                imagedestroy($img);
                $failed++;

                continue;
            }

            ob_start();
            imagewebp($img, null, PosterBackfill::COVER_QUALITY);
            $out = (string) ob_get_clean();
            imagedestroy($img);

            // A fresh filename, not an overwrite: Cloudflare and the browser key their cache on the
            // path and routinely ignore a "?t=" on a static asset, so a same-name write would keep
            // serving the unbranded image (learned the hard way — see ImageStore::putCover).
            $version = bin2hex(random_bytes(4));
            $newPath = 'media/posters/'.$content->id.'-'.$version.'.webp';
            Storage::disk('public')->put($newPath, $out);

            $wasBackdrop = (string) $content->backdrop_path === $rel;
            DB::table('contents')->where('id', $content->id)->update(array_filter([
                'poster_path' => $newPath,
                'poster_hash' => md5($out),
                'poster_watermarked_at' => now(),
                'backdrop_path' => $wasBackdrop ? $newPath : null,
            ], fn ($v) => $v !== null));

            if ($rel !== $newPath) {
                Storage::disk('public')->delete($rel);
            }

            $done++;
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('เสร็จ: ใส่ลายน้ำ %d ใบ · ข้ามเพราะมีลายน้ำเว็บอื่น %d · ล้มเหลว %d',
            $done, $skippedForeign, $failed));

        if (! $dry && $remaining > $titles->count()) {
            $this->comment('ยังเหลืออีก — รันคำสั่งเดิมซ้ำเพื่อทำต่อ');
        }

        return self::SUCCESS;
    }
}
