<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily auto top-up: sync a source's newest pages (new releases sit on page 1) then import the
 * most-viewed not-yet-imported titles. Self-gates on the admin toggle `auto_import_enabled` so the
 * scheduler can always call it — it just no-ops when the admin has it off. Imports use auto-type +
 * auto-genres (so movies/series split correctly and land in real genres), and ImportService scrapes
 * the synopsis on import, so new titles arrive complete.
 *
 * Each source is scheduled INDEPENDENTLY (its own time/weekdays/limit) via routes/console.php, which
 * calls this with the source id: `netwix:auto-import 24hdx --limit=40`. Called with NO source it
 * falls back to the legacy all-sources run (the `auto_import_sources` CSV) so old invocations keep
 * working. See [[NetWix import metadata audit]].
 */
class AutoImportCommand extends Command
{
    protected $signature = 'netwix:auto-import {source? : one source id; omit to run every source in auto_import_sources} {--limit= : titles this run (default: admin setting or 40)}';

    protected $description = 'Auto top-up of new releases, per source (admin-toggleable via auto_import_enabled).';

    public function handle(SourceRegistry $registry, ImportService $importer): int
    {
        if (! Setting::flag('auto_import_enabled', false)) {
            $this->info('auto-import is OFF (admin setting) — skipping.');

            return self::SUCCESS;
        }

        $only = trim((string) $this->argument('source'));
        if ($only !== '') {
            if (! $registry->has($only)) {
                $this->error("unknown source '{$only}'.");

                return self::FAILURE;
            }
            $sources = [$only];
        } else {
            $sources = collect(explode(',', (string) Setting::get('auto_import_sources', '24hdx,wowdrama,anime108,rongyok,anifume')))
                ->map(fn ($s) => trim($s))->filter()->all();
        }

        $perSource = (int) ($this->option('limit') ?: Setting::get('auto_import_per_run', 40));

        foreach ($sources as $sid) {
            if (! $registry->has($sid)) {
                continue;
            }
            $this->importSource($registry, $importer, $sid, $perSource);
        }

        return self::SUCCESS;
    }

    /** Sync a single source's newest pages, then import its top not-yet-imported titles. */
    private function importSource(SourceRegistry $registry, ImportService $importer, string $sid, int $perSource): void
    {
        $source = $registry->get($sid);

        try {
            $synced = $importer->sync($sid, 4); // first pages = newest releases
        } catch (Throwable $e) {
            $synced = 0;
        }

        $ids = SourceTitle::where('source', $sid)->notImported()
            ->orderByDesc('view_count')->orderByDesc('id')->limit($perSource)->pluck('id');

        $ok = 0;
        $skip = 0;
        $fail = 0;
        foreach ($ids as $id) {
            $st = SourceTitle::find($id);
            if (! $st) {
                continue;
            }
            try {
                $imported = $importer->import($st, [
                    'type' => $source->defaultContentType(),
                    'auto_type' => true,
                    'auto_genres' => true,
                    'publish' => true,
                ]);
                $imported === null ? $skip++ : $ok++;
            } catch (Throwable $e) {
                $fail++;
            }
        }
        $this->info("{$sid}: synced {$synced}, imported {$ok}".($skip ? " ({$skip} skipped)" : '').($fail ? " ({$fail} failed)" : '').'.');

        \App\Models\ImportLog::record($sid, 'scheduled', $ok, $skip, $fail, 'นำเข้าอัตโนมัติตามเวลา');
    }
}
