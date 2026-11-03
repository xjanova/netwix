<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Setting;
use App\Support\BackupFinder;
use App\Support\PlaybackHealth;
use App\Support\ReplacementFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily bot: for each auto-suspended (un-playable) title, look for a working stream on another Halim
 * pool site ([App\Support\BackupFinder]); when one is found and verified, point the title's episodes
 * at it (backup_* columns) and re-publish it ([PlaybackHealth::republish]). Self-gates on the admin
 * toggle `backup_finder_enabled` so the scheduler can always call it — it just no-ops when off.
 * Follows the AutoImportCommand pattern.
 */
class FindBackupsCommand extends Command
{
    protected $signature = 'netwix:find-backups
        {--limit= : suspended titles to scan this run (default: admin setting or 30)}
        {--content= : scan only this content id — ignores the suspended filter AND the on/off toggle}';

    protected $description = 'Find backup streams for un-playable titles and auto-republish (admin-toggleable via backup_finder_enabled).';

    public function handle(BackupFinder $finder, ReplacementFinder $replacements): int
    {
        $only = $this->option('content');

        // The single-title form is an admin/manual probe — it runs even when the daily bot is off.
        if (! $only && ! Setting::flag('backup_finder_enabled', false)) {
            $this->info('backup-finder is OFF (admin setting) — skipping.');

            return self::SUCCESS;
        }

        $query = Content::query()->with('episodes');
        if ($only) {
            $query->whereKey($only);
        } else {
            $limit = (int) ($this->option('limit') ?: Setting::get('backup_finder_per_run', 30));
            $query->suspended()->orderByDesc('suspended_at')->limit(max(1, $limit));
        }
        $titles = $query->get();

        $found = 0;
        foreach ($titles as $content) {
            try {
                $backup = $finder->find($content);
            } catch (Throwable $e) {
                $this->warn("  {$content->title}: error — ".mb_substr($e->getMessage(), 0, 120));

                continue;
            }
            if (! $backup) {
                // Not on a Halim pool site — try every other site's synced catalogue (anime sources,
                // wowdrama, a fresh upload on its own site, ...). attach() republishes on success.
                try {
                    $copy = $replacements->rescue($content);
                } catch (Throwable $e) {
                    $copy = null;
                    $this->warn("  {$content->title}: catalogue search error — ".mb_substr($e->getMessage(), 0, 120));
                }
                if ($copy && $copy['kind'] === 'duplicate') {
                    $found++;
                    $this->info("  ✓ {$content->title} → ซ้ำกับเรื่อง #{$copy['duplicate_of']} ที่ออนไลน์อยู่แล้ว (ย้ายคนดูไปเรื่องนั้น)");
                    Log::info('backup-finder: retired a duplicate of a live title', [
                        'content_id' => $content->id, 'title' => $content->title, 'duplicate_of' => $copy['duplicate_of'],
                    ]);

                    continue;
                }
                if ($copy) {
                    $found++;
                    $this->info("  ✓ {$content->title} → ลิ้งค์ทดแทนจาก {$copy['site']}");
                    Log::info('backup-finder: attached catalogue replacement + republished', [
                        'content_id' => $content->id, 'title' => $content->title,
                        'source' => $copy['source'], 'key' => $copy['key'],
                    ]);

                    continue;
                }
                $this->line("  · {$content->title}: no backup found");

                continue;
            }

            $this->applyBackup($content, $backup);
            PlaybackHealth::republish($content);
            $found++;
            $this->info("  ✓ {$content->title} → ลิ้งค์สำรองจาก {$backup['display']}");
            Log::info('backup-finder: applied backup + republished', [
                'content_id' => $content->id, 'title' => $content->title,
                'backup_source' => $backup['source'], 'backup_key' => $backup['key'],
            ]);
        }

        $this->info("scanned {$titles->count()}, backups applied {$found}.");

        return self::SUCCESS;
    }

    /**
     * Point every episode at the backup source. The backup post id resolves each episode via its own
     * `episode` param, so a movie's single episode uses ref "1" and a series' episode N uses "N"
     * (the finder verified episode 1 actually plays before we got here).
     *
     * @param  array{source:string,key:string,ref:string,display:string}  $backup
     */
    private function applyBackup(Content $content, array $backup): void
    {
        foreach ($content->episodes as $ep) {
            $ep->forceFill([
                'backup_source' => $backup['source'],
                'backup_key' => $backup['key'],
                'backup_ref' => $content->type === 'movie' ? '1' : (string) $ep->number,
            ])->save();
        }
    }
}
