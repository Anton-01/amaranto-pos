<?php

/*
|--------------------------------------------------------------------------
| Ecosistema de Respaldo y Rollback — Fase 10
|--------------------------------------------------------------------------
|
| Politica de recuperacion ante desastres del POS. El principio rector es el
| AISLAMIENTO: la boveda vive en un proveedor distinto (Google Cloud Storage)
| al de la infraestructura primaria (DigitalOcean), de modo que un incidente
| que comprometa el Droplet o el cluster de PostgreSQL administrado no alcance
| a los respaldos.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Destino de los Snapshots
    |--------------------------------------------------------------------------
    |
    | `disk` es la boveda aislada. `fallback_disk` entra en juego solo si la
    | boveda no esta configurada: preferimos un respaldo local imperfecto a
    | quedarnos sin respaldo alguno, pero el estado degradado se reporta en
    | la API y en la salida de los comandos.
    |
    */

    'disk' => env('BACKUP_DISK', 'gcs_backups'),
    'fallback_disk' => env('BACKUP_FALLBACK_DISK', 'backups_local'),

    /*
    |--------------------------------------------------------------------------
    | Cifrado en Reposo
    |--------------------------------------------------------------------------
    |
    | El artefacto se cifra ANTES de salir del servidor (AES-256-CBC con
    | derivacion PBKDF2 via openssl), de modo que ni el proveedor de la nube
    | ni quien obtenga acceso de lectura al bucket puede leer el dump.
    |
    | La llave NO debe ser APP_KEY: rotar APP_KEY es una operacion rutinaria y
    | dejaria ilegibles todos los respaldos historicos de golpe. Se exige una
    | llave dedicada y de ciclo de vida independiente.
    |
    */

    'encryption' => [
        'enabled' => (bool) env('BACKUP_ENCRYPTION_ENABLED', true),
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        'cipher' => 'aes-256-cbc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Contenido del Snapshot
    |--------------------------------------------------------------------------
    |
    | Ademas del dump de PostgreSQL se empaquetan los archivos criticos de
    | configuracion. El `.env` real queda FUERA por defecto: contiene secretos
    | en claro y un respaldo es, por definicion, una copia que viaja. Quien
    | necesite recuperacion total del entorno puede activarlo a conciencia.
    |
    */

    'include' => [
        'config_paths' => [
            'config',
            'database/migrations',
        ],
        'env_file' => (bool) env('BACKUP_INCLUDE_ENV', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retencion y Programacion
    |--------------------------------------------------------------------------
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'schedule' => [
        'enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', true),
        'daily_at' => env('BACKUP_SCHEDULE_AT', '03:30'),
        'timezone' => env('BACKUP_SCHEDULE_TIMEZONE', 'America/Mexico_City'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restauracion (Rollback)
    |--------------------------------------------------------------------------
    |
    | `require_confirmation_phrase` es el ultimo candado humano: restaurar
    | SOBRESCRIBE la base de datos de produccion. La frase debe teclearse
    | completa, tanto en el comando como en el endpoint.
    |
    | `pre_restore_snapshot` genera un respaldo del estado ACTUAL antes de
    | pisarlo: sin el, un rollback a un punto equivocado es irreversible.
    |
    */

    'restore' => [
        'require_confirmation_phrase' => env('BACKUP_RESTORE_PHRASE', 'RESTAURAR PRODUCCION'),
        'pre_restore_snapshot' => (bool) env('BACKUP_PRE_RESTORE_SNAPSHOT', true),
        'statement_timeout_seconds' => (int) env('BACKUP_RESTORE_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Binarios de PostgreSQL
    |--------------------------------------------------------------------------
    |
    | Rutas de pg_dump / psql. La imagen de produccion debe incluir el paquete
    | `postgresql-client` (ver backend/Dockerfile.prod).
    |
    */

    'pg_dump_path' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
    'psql_path' => env('BACKUP_PSQL_PATH', 'psql'),

    /*
    | Tiempo maximo de un dump completo, en segundos.
    */
    'dump_timeout_seconds' => (int) env('BACKUP_DUMP_TIMEOUT', 1800),

];
