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

// Drains DownloadPreviewJob (queued when a viewer opens an un-mirrored title).
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()->withoutOverlapping();

// Workers for the admin "สร้างปกตอน" cover generation (GenerateEpisodeThumb).
// These run in CLI (proc_open enabled) — php-fpm has proc_open/exec DISABLED,
// so ffmpeg can only be spawned here, not in the admin web request.
//
// TWO lanes: an on-demand "by title" click lands on `thumbs-now`, a big
// "whole site"/genre run lands on `thumbs`. Worker A drains now-first so a
// small title never queues behind a huge bulk backlog; worker B drains
// bulk-first for throughput (a different --queue order = a different mutex, so
// both run in parallel). --max-time keeps each run short so a killed worker's
// withoutOverlapping mutex self-heals within a few minutes.
Schedule::command('queue:work --queue=thumbs-now,thumbs --stop-when-empty --max-time=110 --timeout=150 --memory=256 --tries=2')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();

Schedule::command('queue:work --queue=thumbs,thumbs-now --stop-when-empty --max-time=110 --timeout=150 --memory=256 --tries=2')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
