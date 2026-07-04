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

// Dedicated worker for the admin "สร้างปกตอน" cover generation (GenerateEpisodeThumb).
// It lives on its OWN queue so heavy download+ffmpeg work never delays the
// user-facing preview downloads on the default queue. Crucially this runs in
// CLI (proc_open enabled) — php-fpm has proc_open/exec DISABLED, so ffmpeg can
// only be spawned here, not in the admin web request. runInBackground() lets it
// run alongside the default worker within the same scheduler minute.
Schedule::command('queue:work --queue=thumbs --stop-when-empty --max-time=50 --timeout=150 --memory=256 --tries=2')
    ->everyMinute()->withoutOverlapping()->runInBackground();
