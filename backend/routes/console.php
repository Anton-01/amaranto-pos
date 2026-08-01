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
