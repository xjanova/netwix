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
// Sync + import new titles from the sources, keep episode-1 preview clips
// topped up, and drain the DB queue (on-demand ep1 downloads dispatched from
// the title page). withoutOverlapping() means a slow run never stacks up.
Schedule::command('netwix:import rongyok --limit=12 --sync')
    ->everySixHours()->withoutOverlapping()->runInBackground();

Schedule::command('netwix:import wowdrama --limit=8 --sync')
    ->dailyAt('04:10')->withoutOverlapping()->runInBackground();

Schedule::command('netwix:previews --limit=40')
    ->hourly()->withoutOverlapping()->runInBackground();

// Daily auto top-up of new releases. Self-gates on the admin toggle `auto_import_enabled` (set on
// /admin/import), so it's safe to always schedule — it no-ops when the admin has it off.
Schedule::command('netwix:auto-import')
    ->dailyAt('05:00')->withoutOverlapping()->runInBackground();

// Daily backup-link finder: re-source auto-suspended (un-playable) titles from another Halim pool
// site and auto-republish. Self-gates on the admin toggle `backup_finder_enabled` (set on
// /admin/backups). Runs after auto-import so any newly-imported titles are considered too.
Schedule::command('netwix:find-backups')
    ->dailyAt('05:30')->withoutOverlapping()->runInBackground();

// Drains DownloadPreviewJob (queued when a viewer opens an un-mirrored title).
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()->withoutOverlapping();

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
