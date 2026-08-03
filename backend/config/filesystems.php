<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | gcs_backups — Boveda AISLADA de respaldos (Fase 10)
        |----------------------------------------------------------------------
        |
        | Bucket dedicado en Google Cloud Storage, deliberadamente en un
        | proveedor DISTINTO al de la infraestructura primaria (DigitalOcean).
        | Un incidente que comprometa el Droplet o el cluster de PostgreSQL
        | administrado no alcanza a los respaldos: ese es el aislamiento.
        |
        | El driver `gcs` lo registra App\Providers\CloudStorageServiceProvider
        | sobre league/flysystem-google-cloud-storage.
        |
        | `throw => true` a proposito: en un flujo de recuperacion ante
        | desastres, un fallo silencioso de escritura es peor que una
        | excepcion ruidosa — creerias tener respaldo cuando no lo tienes.
        |
        */
        'gcs_backups' => [
            'driver' => 'gcs',
            'project_id' => env('GCP_PROJECT_ID'),

            // Ruta al JSON de la Service Account...
            'key_file_path' => env('GCP_KEY_FILE_PATH'),
            // ...o el JSON completo en linea (util en contenedores efimeros).
            'key_file_json' => env('GCP_KEY_FILE_JSON'),

            'bucket' => env('GCP_BACKUPS_BUCKET', 'cronos-pos-backups-isolation'),
            'path_prefix' => env('GCP_BACKUPS_PREFIX', 'snapshots'),
            'visibility' => 'private',
            'throw' => true,
        ],

        /*
        | Respaldo local de emergencia. Si GCS no esta configurado, el servicio
        | de backups cae aqui para no dejar al sistema SIN respaldo alguno.
        | No sustituye al aislamiento: vive en el mismo disco que la app.
        */
        'backups_local' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
