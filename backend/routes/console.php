<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {$this->comment(Inspiring::quote());})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler — Automatic Cash Register Closure
|--------------------------------------------------------------------------
|
| `timezone()` ata este comando al reloj de pared del negocio: sin el, las
| 21:00 se evaluarian en la zona del scheduler y el cierre caeria a otra hora.
| Se declara ANTES de `at()` para que la zona quede fijada al leer la
| expresion.
|
| El literal se mantiene A PROPOSITO, y es la UNICA excepcion a la
| centralizacion de la seccion 53: desde que el contenedor `scheduler` corre
| con `TZ=America/Mexico_City` esta linea es redundante en la practica, pero
| se conserva como candado. El arqueo de las 21:00 es la operacion financiera
| mas critica del dia y no debe poder moverse de hora por un `APP_TIMEZONE`
| mal escrito en el `.env` ni por un `TZ` que alguien quite del compose:
| aqui la redundancia es la garantia, no una duplicacion por descuido.
|
*/
Schedule::command('cronos:auto-close-registers --source=scheduler')
    ->weekdays()
    ->timezone('America/Mexico_City')
    ->at('21:00')
    ->withoutOverlapping()
    ->onOneServer();

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
