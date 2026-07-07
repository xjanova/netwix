<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---- Automatic catalogue pipeline -----------------------------------------
// Requires ONE cron line on the server:
//   * * * * * cd <app> && php artisan schedule:run >> /dev/null 2>&1
// Sync + import new titles from the sources. withoutOverlapping() means a slow
// run never stacks up.
Schedule::command('netwix:import rongyok --limit=12 --sync')
    ->everySixHours()->withoutOverlapping()->runInBackground();

Schedule::command('netwix:import wowdrama --limit=8 --sync')
    ->dailyAt('04:10')->withoutOverlapping()->runInBackground();

// DISABLED — auto ep1 mirroring turned off (owner: unnecessary + eats disk). ep1 now streams
// on demand like every other episode / other sites. The mirror system is intact and can be
// driven manually: `php artisan netwix:previews` (ep1 clips) or /admin/storage (any episode /
// whole title). Re-enable auto top-up by uncommenting the line below.
// Schedule::command('netwix:previews --limit=40')
//     ->hourly()->withoutOverlapping()->runInBackground();

// Daily auto top-up of new releases. Time + days are admin-configurable (Setting `auto_import_time` =
// "HH:MM", `auto_import_days` = CSV of 0-6 where 0=Sun; empty = every day) on /admin/import. Reads are
// cache-backed (Setting::map), so evaluating this every scheduler tick is cheap; wrapped so a DB blip
// falls back to the 05:00-daily default instead of breaking every other scheduled task. Self-gates on
// the `auto_import_enabled` toggle inside the command, so it's safe to always schedule.
$aiTime = '05:00';
$aiDays = '';
try {
    $aiTime = (string) (\App\Models\Setting::get('auto_import_time', '05:00') ?: '05:00');
    $aiDays = (string) \App\Models\Setting::get('auto_import_days', '');
} catch (\Throwable $e) {
    // DB not ready (e.g. pre-migrate) → defaults
}
if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $aiTime)) {
    $aiTime = '05:00';
}
$autoImport = Schedule::command('netwix:auto-import')
    ->dailyAt($aiTime)->withoutOverlapping()->runInBackground();
$aiDayList = array_values(array_filter(
    array_map('intval', array_filter(explode(',', $aiDays), fn ($d) => trim($d) !== '')),
    fn ($d) => $d >= 0 && $d <= 6,
));
if ($aiDayList) {
    $autoImport->days($aiDayList);   // restrict to chosen weekdays; empty = every day
}

// Nightly episode refresh: re-scrape the episode list of recently-imported, still-airing series so a
// title that gained new episodes at the source (dramas/anime air weekly) doesn't stay frozen at its
// first-import count — and any series we first stored as a 1-episode "movie" gets re-typed. Bounded
// (--limit) + --airing-only so it never re-scrapes the whole ~4k-series catalogue in one night; the
// least-recently-touched are picked first so the pool rotates. Full sweeps run on demand via the
// command (no --airing-only). See [[NetWix — airing series stuck at old episode count]].
Schedule::command('netwix:refresh-episodes --airing-only --limit=200 --sleep=250')
    ->dailyAt('03:20')->withoutOverlapping()->runInBackground();

// Daily backup-link finder: re-source auto-suspended (un-playable) titles from another Halim pool
// site and auto-republish. Self-gates on the admin toggle `backup_finder_enabled` (set on
// /admin/backups). Runs after auto-import so any newly-imported titles are considered too.
Schedule::command('netwix:find-backups')
    ->dailyAt('05:30')->withoutOverlapping()->runInBackground();

// Auto-watcher for real USDT (BSC) deposits: settle paid gold/Pro orders by
// reading the chain via BscScan. Self-gates when payments are off / no wallet set,
// so it's safe to always schedule. The "ตรวจสอบเดี๋ยวนี้" button verifies on demand.
Schedule::command('usdt:watch')
    ->everyMinute()->withoutOverlapping()->runInBackground();

// Drains the default DB queue (thumb seeding, etc.). DownloadPreviewJob is no longer
// auto-dispatched, but stays queue-drainable if invoked manually.
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()->withoutOverlapping();

// Catalogue sync worker (SyncCatalogJob). A manual "ซิงค์แคตตาล็อก" runs here in the background, NOT in
// the web request: a full scrape (24hdx=66 pages, 9nung=92) outran Cloudflare's ~100s timeout, so the
// browser retried and stacked concurrent 30-min scrapes (incident 2026-07-06). Long --timeout so even a
// slow/throttled source finishes; --stop-when-empty so idle minutes exit fast; withoutOverlapping so
// only one sync worker runs (single-flight is also enforced per source by the job middleware).
Schedule::command('queue:work --queue=sync --stop-when-empty --max-time=600 --timeout=650 --memory=512 --tries=1')
    ->everyMinute()->withoutOverlapping()->runInBackground();

// Workers for the admin "สร้างปกตอน" cover generation (GenerateEpisodeThumb).
// These run in CLI (proc_open enabled) — php-fpm has proc_open/exec DISABLED,
// so ffmpeg can only be spawned here, not in the admin web request.
//
// A POOL of 3 parallel agents drains the cover queue. All prioritise the
// on-demand `thumbs-now` lane (a "by title" click) over the bulk `thumbs` lane,
// so a small title never queues behind a big "whole site"/genre backlog — yet
// when only bulk work exists all 3 chew it in parallel (~3x faster). The DB
// queue's row-locking guarantees no two agents grab the same episode.
//
// Slightly different --max-time per agent = a different command string = a
// different withoutOverlapping mutex, which is what lets all 3 run at once.
//
// NB: deliberately NO --stop-when-empty. Agents stay alive polling every 1s for
// the whole --max-time window, so a freshly-clicked job is picked up in ~1-2s
// (not up to a minute) and the progress bar moves in near real time. Each agent
// recycles every ~2 min (fresh code + the withoutOverlapping(5) mutex self-heals
// if an agent is ever killed).
foreach ([110, 112, 114] as $maxTime) {
    Schedule::command("queue:work --queue=thumbs-now,thumbs --sleep=1 --max-time={$maxTime} --timeout=150 --memory=256 --tries=2")
        ->everyMinute()->withoutOverlapping(5)->runInBackground();
}

// Workers for the marketing clip cutter (GenerateMarketingClip). Same rule as the
// cover workers: fpm can't spawn ffmpeg, so clips are cut here on the CLI. A clip is a
// full re-encode (heavier + longer than a frame grab) and bursts of segment downloads
// hit the source CDN, so a SMALLER pool of 2 agents is deliberate. Distinct --max-time
// per agent = distinct command string = distinct withoutOverlapping mutex → both run in
// parallel; --timeout=310 covers the job's 300s ceiling.
foreach ([220, 224] as $maxTime) {
    Schedule::command("queue:work --queue=clips --sleep=1 --max-time={$maxTime} --timeout=310 --memory=512 --tries=2")
        ->everyMinute()->withoutOverlapping(5)->runInBackground();
}
