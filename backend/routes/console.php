<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {$this->comment(Inspiring::quote());})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler — Automatic Cash Register Closure
|--------------------------------------------------------------------------
*/
Schedule::command('cronos:auto-close-registers')->weekdays()->at('21:00')->timezone('America/Mexico_City')->withoutOverlapping()->onOneServer();

/*
|--------------------------------------------------------------------------
| Scheduler — Backup and Maintenance (suspended)
|--------------------------------------------------------------------------
| Both maintenance schedules are disabled on purpose and left in place as
| documentation of the intended cadence.
|
| - cronos:backup-run took a nightly pg_dump into the isolated GCS vault.
| - cronos:telemetry-prune purged expired backups and job_execution_logs
|   rows; while it stays off, that table grows without a ceiling (every
|   queued email leaves one row), so it needs manual pruning.
|
| Both commands remain fully operational and can still be triggered by hand
| with `php artisan`. Re-enable by uncommenting the blocks below.
*/
// Schedule::command('cronos:backup-run --trigger=scheduled')
//     ->dailyAt(config('backup.schedule.daily_at', '03:30'))
//     ->timezone(config('backup.schedule.timezone', 'America/Mexico_City'))
//     ->when(fn () => (bool) config('backup.schedule.enabled', true))
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->onOneServer();

// Schedule::command('cronos:telemetry-prune --backups')
//     ->dailyAt('04:15')
//     ->timezone('America/Mexico_City')
//     ->withoutOverlapping()
//     ->onOneServer();
