<?php

/*
|--------------------------------------------------------------------------
| Telemetria de Jobs en Segundo Plano — Fase 10
|--------------------------------------------------------------------------
|
| La captura se engancha a los eventos NATIVOS de la cola de Laravel, asi que
| ningun job necesita instrumentarse a mano. El coste es una escritura en
| PostgreSQL por transicion de estado; estos parametros permiten acotarlo.
|
*/

return [

    'jobs' => [

        'enabled' => (bool) env('JOB_TELEMETRY_ENABLED', true),

        /*
         * Jobs que NO se registran. Se compara por coincidencia de subcadena
         * contra el nombre de clase, asi que admite prefijos de namespace.
         *
         * Critico: el propio job de poda queda excluido para que la limpieza
         * del histórico no genere histórico nuevo en cada corrida.
         */
        'ignore' => [
            'Illuminate\Queue\CallQueuedClosure',
        ],

        /*
         * Recorte de la traza de excepcion. Una traza completa de Laravel
         * ronda los 30-60 KB; guardarla integra multiplicaria el peso de la
         * tabla sin aportar valor forense: el origen del fallo esta arriba.
         */
        'max_trace_length' => (int) env('JOB_TELEMETRY_MAX_TRACE', 20000),

        /*
         * Retencion del histórico, en dias. `cronos:jobs-prune` corre a diario
         * desde el scheduler.
         */
        'retention_days' => (int) env('JOB_TELEMETRY_RETENTION_DAYS', 30),

    ],

];
