<?php

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use RuntimeException;

/**
 * Registro del driver `gcs` para Google Cloud Storage (Fase 10).
 *
 * Laravel no trae adaptador nativo para GCS, asi que se enchufa via
 * `Storage::extend` sobre league/flysystem-google-cloud-storage. A partir de
 * ese momento el resto del codigo habla unicamente con la fachada `Storage`
 * y desconoce el proveedor — lo que mantiene el servicio de respaldos
 * testeable con `Storage::fake()` y portable si algun dia se migra la boveda.
 */
class CloudStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // La clausura se declara `static` A PROPOSITO: asi no puede recibir un
        // `$this` y ningun refactor futuro puede volver a colgarle una llamada
        // a un metodo de instancia. Laravel resuelve este callback desde dentro
        // de su maquinaria de drivers (FilesystemManager::callCustomCreator),
        // muy lejos del proveedor que lo registro, y una llamada `$this->…()`
        // ahi dentro reventaba con
        //   "Call to undefined method League\Flysystem\Filesystem::…()"
        // — un error fatal que subia hasta el `catch (Throwable)` de
        // BackupController::index() y se presentaba al operador como un 503
        // opaco, escondiendo la causa real (falta el adaptador de GCS).
        Storage::extend('gcs', static function ($app, array $config): FilesystemAdapter {
            // Guardia de dependencias EN LINEA. El adaptador de GCS es una
            // dependencia opcional: el POS arranca sin ella y solo la necesita
            // quien active la boveda. Si falta hay que decir EXACTAMENTE que
            // instalar, no reventar con un "class not found".
            if (! class_exists(GoogleCloudStorageAdapter::class) || ! class_exists(StorageClient::class)) {
                throw new RuntimeException(
                    'El disco "gcs_backups" requiere el adaptador de Google Cloud Storage. '
                    .'Ejecuta: composer require league/flysystem-google-cloud-storage'
                );
            }

            $client = new StorageClient(self::clientConfig($config));

            $bucket = $client->bucket((string) $config['bucket']);

            $adapter = new GoogleCloudStorageAdapter(
                $bucket,
                (string) ($config['path_prefix'] ?? '')
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    /**
     * Credenciales de la Service Account. Se aceptan las dos formas de
     * inyectarlas y se prefiere la ruta a archivo, que evita que el JSON
     * completo quede volcado en la tabla de procesos o en un `docker inspect`.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function clientConfig(array $config): array
    {
        $client = array_filter([
            'projectId' => $config['project_id'] ?? null,
        ]);

        $keyFilePath = $config['key_file_path'] ?? null;

        if (filled($keyFilePath)) {
            if (! is_readable($keyFilePath)) {
                throw new RuntimeException(
                    "GCP_KEY_FILE_PATH apunta a un archivo ilegible: {$keyFilePath}"
                );
            }

            $client['keyFilePath'] = $keyFilePath;

            return $client;
        }

        $keyFileJson = $config['key_file_json'] ?? null;

        if (filled($keyFileJson)) {
            $decoded = json_decode((string) $keyFileJson, true);

            if (! is_array($decoded)) {
                throw new RuntimeException('GCP_KEY_FILE_JSON no contiene un JSON valido.');
            }

            $client['keyFile'] = $decoded;

            return $client;
        }

        // Sin credenciales explicitas se delega en Application Default
        // Credentials (metadata server de GCP, gcloud CLI, GOOGLE_APPLICATION_CREDENTIALS).
        return $client;
    }

    /**
     * Disponibilidad del adaptador opcional de GCS.
     *
     * Publica y estatica a proposito: `BackupService::vaultConfigured()` la
     * consulta ANTES de pedirle el disco a `Storage`, para degradarse al disco
     * local en vez de resolver un driver que no puede construirse.
     */
    public static function adapterAvailable(): bool
    {
        return class_exists(GoogleCloudStorageAdapter::class) && class_exists(StorageClient::class);
    }
}
