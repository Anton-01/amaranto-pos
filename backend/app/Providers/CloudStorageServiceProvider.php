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
        Storage::extend('gcs', function ($app, array $config) {
            $this->assertDependenciesInstalled();

            $client = new StorageClient($this->clientConfig($config));

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
    private function clientConfig(array $config): array
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
     * El adaptador de GCS es una dependencia opcional: el POS arranca sin ella
     * y solo la necesita quien active la boveda de respaldos. Si falta, hay que
     * decir EXACTAMENTE que instalar, no reventar con un "class not found".
     */
    private function assertDependenciesInstalled(): void
    {
        if (class_exists(GoogleCloudStorageAdapter::class) && class_exists(StorageClient::class)) {
            return;
        }

        throw new RuntimeException(
            'El disco "gcs_backups" requiere el adaptador de Google Cloud Storage. '
            .'Ejecuta: composer require league/flysystem-google-cloud-storage'
        );
    }
}
