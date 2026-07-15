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

// Auto top-up of new releases — schedulable PER SOURCE. Each source has its own time + weekdays +
// per-run limit, saved as JSON `auto_import_schedules` on /admin/import; we register one scheduled
// `netwix:auto-import {source}` per ENABLED source at its own time/days. The command self-gates on the
// master `auto_import_enabled` toggle, so registering these every tick is safe. Reads are cache-backed
// (Setting::map) and wrapped so a DB blip can't break the rest of the schedule. Back-compat: if no
// per-source table has been saved yet, fall back to the single global `auto_import_time`/
// `auto_import_days` run that loops every source in `auto_import_sources`.
$aiClampTime = static fn ($t): string => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', (string) $t) ? (string) $t : '05:00';
$aiCleanDays = static fn ($d): array => array_values(array_filter(
    array_map('intval', is_array($d) ? $d : []),
    fn ($x) => $x >= 0 && $x <= 6,
));

$aiSchedules = [];
try {
    $aiRaw = (string) \App\Models\Setting::get('auto_import_schedules', '');
    $aiDecoded = $aiRaw !== '' ? json_decode($aiRaw, true) : null;
    $aiSchedules = is_array($aiDecoded) ? $aiDecoded : [];
} catch (\Throwable $e) {
    // DB not ready (e.g. pre-migrate) → no per-source schedules; legacy fallback below.
}

if ($aiSchedules) {
    // Per-source: distinct command string per source → distinct withoutOverlapping mutex, so a slow
    // source never blocks another and they run independently at their own configured time.
    foreach ($aiSchedules as $aiSid => $aiCfg) {
        if (! is_array($aiCfg) || empty($aiCfg['enabled'])) {
            continue;
        }
        $aiSid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $aiSid);
        if ($aiSid === '') {
            continue;
        }
        $aiLimit = (int) ($aiCfg['limit'] ?? 0);
        $aiCmd = 'netwix:auto-import '.$aiSid.($aiLimit > 0 ? ' --limit='.$aiLimit : '');
        $aiEvent = Schedule::command($aiCmd)
            ->dailyAt($aiClampTime($aiCfg['time'] ?? '05:00'))
            ->withoutOverlapping()->runInBackground();
        if ($aiDayList = $aiCleanDays($aiCfg['days'] ?? [])) {
            $aiEvent->days($aiDayList);   // restrict to chosen weekdays; empty = every day
        }
    }
} else {
    // Legacy single global run — until the admin saves a per-source schedule table on /admin/import.
    $aiTime = '05:00';
    $aiDays = '';
    try {
        $aiTime = (string) (\App\Models\Setting::get('auto_import_time', '05:00') ?: '05:00');
        $aiDays = (string) \App\Models\Setting::get('auto_import_days', '');
    } catch (\Throwable $e) {
        // DB not ready (e.g. pre-migrate) → defaults
    }
    $autoImport = Schedule::command('netwix:auto-import')
        ->dailyAt($aiClampTime($aiTime))->withoutOverlapping()->runInBackground();
    $aiDayList = $aiCleanDays(array_map('intval', array_filter(explode(',', $aiDays), fn ($d) => trim($d) !== '')));
    if ($aiDayList) {
        $autoImport->days($aiDayList);
    }
}

// Nightly episode refresh: re-scrape the episode list of recently-imported, still-airing series so a
// title that gained new episodes at the source (dramas/anime air weekly) doesn't stay frozen at its
// first-import count — and any series we first stored as a 1-episode "movie" gets re-typed. Bounded
// (--limit) + --airing-only so it never re-scrapes the whole ~4k-series catalogue in one night; the
// least-recently-touched are picked first so the pool rotates. Full sweeps run on demand via the
// command (no --airing-only). See [[NetWix — airing series stuck at old episode count]].
Schedule::command('netwix:refresh-episodes --airing-only --limit=200 --sleep=250')
    ->dailyAt('03:20')->withoutOverlapping()->runInBackground();

// Nightly: re-check 9nung playability so a re-imported/updated title auto-publishes when it carries a
// clean fembed→vdohls stream (and hides one that flipped to abyss). 9nung is a MIXED source (~24%
// playable fembed, the rest abyss ad-traps); `hidden_sources=9nung` keeps imports hidden by default and
// this promotes exactly the playable ones. Gentle + bounded so it never hammers the source.
Schedule::command('netwix:recheck-playable 9nung --limit=3000 --sleep=250')
    ->dailyAt('04:40')->withoutOverlapping()->runInBackground();

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

// ---- Clip marketing campaigns (Phase 3) -----------------------------------
// The publisher heartbeat: every 5 minutes it fires any campaign whose slot is due (pick a
// title → cut a clip → auto-post to Facebook). It is CHEAP — it only writes rows + enqueues,
// never ffmpeg — so it's safe on every tick. Self-gates on the `clip_campaigns_enabled`
// kill-switch (admin: /admin/clip-campaigns), so it's fine to always schedule. The DB unique
// key on clip_campaign_posts makes a double-fire inside a slot window a no-op.
Schedule::command('netwix:clips:publish')
    ->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// The Facebook publish lane (PostClipToFacebook): a light HTTP upload — FB pulls the hosted
// clip URL — so, unlike the ffmpeg clip cutter, ONE modest worker is plenty and it won't load
// the CPU. --stop-when-empty keeps idle minutes cheap; a post lands within ~a minute of its
// clip being cut. In dry-run (no FB token) the job still runs and records the simulated post.
Schedule::command('queue:work --queue=clips-post --stop-when-empty --sleep=2 --max-time=55 --timeout=250 --memory=256 --tries=3')
    ->everyMinute()->withoutOverlapping()->runInBackground();

// FULL-EPISODE campaign cuts (clip_campaigns.full_episode): downloading + re-encoding a whole
// episode runs for tens of minutes, which would jam the 2-worker clips pool (310s timeout).
// So: ONE dedicated worker, hours-scale --timeout, single attempt. withoutOverlapping(120)
// guarantees a second encoder never stacks on the first — the 2026-07-06 box crash was
// exactly stacked ffmpeg workers, and full episodes are the heaviest cut we have.
Schedule::command('queue:work --queue=clips-heavy --stop-when-empty --sleep=2 --max-time=280 --timeout=5430 --memory=1024 --tries=1')
    ->everyFiveMinutes()->withoutOverlapping(120)->runInBackground();

// Retention: marketing-clip mp4/poster files are only needed for ~2 weeks (review + FB fetch).
// Purge files older than 15 days nightly but KEEP the rows as history (caption, posted_at, FB id).
Schedule::command('netwix:clips:purge-files --days=15')
    ->dailyAt('04:20')->withoutOverlapping()->runInBackground();
