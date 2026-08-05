<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler — Cierre Automatico de Cajas
|--------------------------------------------------------------------------
| Todos los dias a las 21:00 hora de operacion (America/Mexico_City) se
| cierran las cajas abiertas sin arqueo bajo el usuario System Automated
| Process. withoutOverlapping evita dobles ejecuciones si una corrida se
| alarga; onOneServer cubre despliegues con mas de un contenedor de cron.
| El cron del despliegue en produccion ya invoca schedule:run cada minuto
| (FASE 7: deploy DigitalOcean).
*/
Schedule::command('cronos:auto-close-registers')
    ->dailyAt('21:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Scheduler — Respaldo Diario a la Boveda Aislada (Fase 10)
|--------------------------------------------------------------------------
| A las 03:30, con el POS ya cerrado y despues del cierre automatico de las
| 21:00: el snapshot captura la jornada completa, incluidos los arqueos.
|
| runInBackground evita que un pg_dump largo retrase al resto del scheduler,
| y withoutOverlapping impide que un respaldo lento se solape con el del dia
| siguiente y duplique carga sobre PostgreSQL.
*/
Schedule::command('cronos:backup-run --trigger=scheduled')
    ->dailyAt(config('backup.schedule.daily_at', '03:30'))
    ->timezone(config('backup.schedule.timezone', 'America/Mexico_City'))
    ->when(fn () => (bool) config('backup.schedule.enabled', true))
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Scheduler — Poda de Telemetria y Respaldos Vencidos (Fase 10)
|--------------------------------------------------------------------------
| A las 04:15, despues del respaldo: primero se asegura la copia del dia y
| solo entonces se purgan las vencidas. Sin esta poda, job_execution_logs
| crece sin techo (cada correo encolado deja una fila).
*/
Schedule::command('cronos:telemetry-prune --backups')
    ->dailyAt('04:15')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();
