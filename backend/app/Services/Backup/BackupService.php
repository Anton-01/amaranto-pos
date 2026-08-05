<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Motor de respaldo y recuperacion ante desastres (Fase 10).
 *
 * Un snapshot son DOS objetos en la boveda:
 *
 *   <nombre>.tar.gz[.enc]   Artefacto: dump de PostgreSQL + configuracion.
 *   <nombre>.manifest.json  Ficha con el checksum SHA-256 del artefacto.
 *
 * El manifiesto viaja en claro a proposito: hay que poder listar y auditar los
 * puntos de restauracion sin descifrar nada. El artefacto, en cambio, se cifra
 * con AES-256 antes de salir del servidor.
 *
 * Todo el I/O remoto pasa por la fachada `Storage`, de modo que el motor no
 * sabe si la boveda es Google Cloud Storage o un disco local — y las pruebas
 * pueden usar `Storage::fake()`.
 */
class BackupService
{
    /** Subcarpeta de trabajo bajo storage/app. Se limpia siempre. */
    private const WORK_DIR = 'backup-workspace';

    private const DUMP_FILE = 'database.sql';

    private const METADATA_FILE = 'metadata.json';

    /**
     * Traza de auditoria acumulada durante la operacion en curso. Permite
     * reinyectarla si una restauracion sobrescribe la tabla `audit_logs`.
     *
     * @var list<array<string, mixed>>
     */
    private array $trail = [];

    /**
     * Genera un punto de restauracion completo y lo deposita en la boveda.
     *
     * @param  string  $trigger  Origen: manual, scheduled, pre-restore...
     *
     * @throws RuntimeException
     */
    public function create(?User $actor = null, string $trigger = 'manual'): BackupManifest
    {
        $this->assertBinary(config('backup.pg_dump_path', 'pg_dump'), 'pg_dump');

        $name = $this->generateName($trigger);
        $workspace = $this->makeWorkspace($name);

        try {
            $contents = [];

            $this->dumpDatabase($workspace.'/'.self::DUMP_FILE);
            $contents[] = self::DUMP_FILE;

            $contents = array_merge($contents, $this->copyConfiguration($workspace));

            File::put($workspace.'/'.self::METADATA_FILE, $this->buildMetadata($name, $trigger, $actor));
            $contents[] = self::METADATA_FILE;

            $archive = $this->compress($workspace, $name);
            $artifact = $this->encrypt($archive);

            $manifest = new BackupManifest(
                name: $name,
                artifact: basename($artifact),
                checksum: hash_file('sha256', $artifact),
                size_bytes: (int) filesize($artifact),
                encrypted: $this->encryptionEnabled(),
                cipher: $this->encryptionEnabled() ? (string) config('backup.encryption.cipher') : 'none',
                database: (string) config('database.connections.pgsql.database'),
                created_at: now()->toIso8601String(),
                created_by_id: $actor?->id,
                created_by_name: $actor?->name ?? 'System Automated Process',
                trigger: $trigger,
                contents: $contents,
                app_env: (string) config('app.env'),
            );

            $this->upload($artifact, $manifest);

            $this->audit('backup.created', $actor, [
                'snapshot' => $manifest->name,
                'checksum' => $manifest->checksum,
                'size_bytes' => $manifest->size_bytes,
                'encrypted' => $manifest->encrypted,
                'disk' => $this->diskName(),
                'trigger' => $trigger,
            ]);

            Log::info('[Backup] Snapshot generado.', [
                'snapshot' => $manifest->name,
                'disk' => $this->diskName(),
                'size' => $manifest->humanSize(),
            ]);

            return $manifest;
        } finally {
            // El workspace contiene el dump EN CLARO: se borra pase lo que pase.
            $this->cleanup($workspace);

            // Y tambien el artefacto local ya subido. Sin esto, cada respaldo
            // deja su copia en storage/app y el disco del Droplet se llena en
            // silencio hasta que un dia el pg_dump falla por falta de espacio.
            if (isset($artifact)) {
                File::delete($artifact);
            }
        }
    }

    /**
     * Catalogo de puntos de restauracion disponibles en la boveda, del mas
     * reciente al mas antiguo.
     *
     * @return list<BackupManifest>
     */
    public function listSnapshots(): array
    {
        $disk = $this->disk();

        $manifests = collect($disk->files())
            ->filter(fn (string $path) => str_ends_with($path, '.manifest.json'))
            ->map(function (string $path) use ($disk): ?BackupManifest {
                try {
                    $decoded = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

                    return BackupManifest::fromArray($decoded);
                } catch (Throwable $e) {
                    // Un manifiesto corrupto no debe ocultar los sanos.
                    Log::warning('[Backup] Manifiesto ilegible en la boveda.', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            })
            ->filter()
            ->sortByDesc(fn (BackupManifest $m) => $m->created_at)
            ->values();

        return $manifests->all();
    }

    public function findSnapshot(string $name): BackupManifest
    {
        $path = $this->manifestPath($name);
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            throw new RuntimeException("El punto de restauracion '{$name}' no existe en la boveda.");
        }

        return BackupManifest::fromArray(
            json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Descarga el artefacto y contrasta su SHA-256 contra el manifiesto.
     *
     * @return array{valid: bool, expected: string, actual: string, size_bytes: int}
     */
    public function verify(string $name): array
    {
        $manifest = $this->findSnapshot($name);
        $local = $this->download($manifest);

        try {
            $actual = hash_file('sha256', $local);

            return [
                'valid' => hash_equals($manifest->checksum, $actual),
                'expected' => $manifest->checksum,
                'actual' => $actual,
                'size_bytes' => (int) filesize($local),
            ];
        } finally {
            File::delete($local);
        }
    }

    /**
     * ROLLBACK: restaura la base de datos a un punto anterior.
     *
     * Secuencia deliberada, cada paso condicionado al anterior:
     *
     *   1. Snapshot de seguridad del estado ACTUAL (sin el, el rollback a un
     *      punto equivocado seria irreversible).
     *   2. Descarga del artefacto elegido.
     *   3. Validacion de integridad por checksum — si falla, se aborta ANTES
     *      de tocar la base de datos.
     *   4. Descifrado y extraccion.
     *   5. Restauracion TRANSACCIONAL: psql --single-transaction con
     *      ON_ERROR_STOP=1. O se aplica el dump entero, o no se aplica nada.
     *
     * @return array{snapshot: string, safety_snapshot: string|null, checksum_verified: bool, duration_ms: int}
     *
     * @throws RuntimeException
     */
    public function restore(string $name, User $actor, string $confirmationPhrase): array
    {
        $expectedPhrase = (string) config('backup.restore.require_confirmation_phrase');

        if (! hash_equals($expectedPhrase, trim($confirmationPhrase))) {
            throw new RuntimeException('Frase de confirmacion incorrecta. La restauracion NO se ejecuto.');
        }

        $this->assertBinary(config('backup.psql_path', 'psql'), 'psql');

        $manifest = $this->findSnapshot($name);
        $startedAt = microtime(true);

        $this->audit('backup.restore.started', $actor, [
            'snapshot' => $manifest->name,
            'checksum' => $manifest->checksum,
            'disk' => $this->diskName(),
        ]);

        $safetySnapshot = null;

        if (config('backup.restore.pre_restore_snapshot', true)) {
            try {
                $safetySnapshot = $this->create($actor, 'pre-restore')->name;
            } catch (Throwable $e) {
                $this->audit('backup.restore.aborted', $actor, [
                    'snapshot' => $manifest->name,
                    'reason' => 'No se pudo generar el respaldo de seguridad previo.',
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Abortado: no se pudo respaldar el estado actual antes de sobrescribirlo. '.$e->getMessage(),
                    previous: $e
                );
            }
        }

        $artifact = $this->download($manifest);
        $workspace = $this->makeWorkspace('restore-'.$manifest->name);

        try {
            $actualChecksum = hash_file('sha256', $artifact);

            if (! hash_equals($manifest->checksum, $actualChecksum)) {
                $this->audit('backup.restore.integrity_failed', $actor, [
                    'snapshot' => $manifest->name,
                    'expected' => $manifest->checksum,
                    'actual' => $actualChecksum,
                ]);

                throw new RuntimeException(
                    'INTEGRIDAD COMPROMETIDA: el checksum del artefacto no coincide con el manifiesto. '
                    .'La restauracion se aborto sin tocar la base de datos.'
                );
            }

            $archive = $this->decrypt($artifact, $manifest);
            $this->extract($archive, $workspace);

            $dump = $workspace.'/'.self::DUMP_FILE;

            if (! File::exists($dump)) {
                throw new RuntimeException('El snapshot no contiene un dump de base de datos utilizable.');
            }

            $this->restoreDatabase($dump);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            /*
             * El dump acaba de SOBRESCRIBIR `audit_logs` con el contenido del
             * snapshot: todo lo auditado durante este rollback (el inicio, el
             * respaldo de seguridad) se perdio junto con el resto del estado.
             * Un rollback que borra su propia evidencia forense es inaceptable
             * en un sistema financiero, asi que la traza se reinyecta sobre la
             * base ya restaurada.
             */
            $this->replayTrail();

            $this->audit('backup.restore.completed', $actor, [
                'snapshot' => $manifest->name,
                'safety_snapshot' => $safetySnapshot,
                'duration_ms' => $durationMs,
                'checksum_verified' => true,
            ]);

            Log::warning('[Backup] ROLLBACK EJECUTADO.', [
                'snapshot' => $manifest->name,
                'actor' => $actor->email,
                'safety_snapshot' => $safetySnapshot,
            ]);

            return [
                'snapshot' => $manifest->name,
                'safety_snapshot' => $safetySnapshot,
                'checksum_verified' => true,
                'duration_ms' => $durationMs,
            ];
        } catch (Throwable $e) {
            $this->audit('backup.restore.failed', $actor, [
                'snapshot' => $manifest->name,
                'safety_snapshot' => $safetySnapshot,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            File::delete($artifact);
            $this->cleanup($workspace);
        }
    }

    /**
     * Purga los snapshots mas antiguos que la ventana de retencion.
     *
     * @return list<string> Nombres eliminados.
     */
    public function prune(?int $days = null, ?User $actor = null): array
    {
        $days = $days ?? (int) config('backup.retention_days', 30);
        $cutoff = now()->subDays($days);
        $disk = $this->disk();
        $deleted = [];

        foreach ($this->listSnapshots() as $manifest) {
            if (blank($manifest->created_at) || $cutoff->lessThanOrEqualTo($manifest->created_at)) {
                continue;
            }

            $disk->delete($manifest->artifact);
            $disk->delete($this->manifestPath($manifest->name));
            $deleted[] = $manifest->name;
        }

        if ($deleted !== []) {
            $this->audit('backup.pruned', $actor, [
                'retention_days' => $days,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * Diagnostico de la boveda para el panel de administracion: dice si el
     * aislamiento en GCP esta realmente activo o si el sistema opera degradado.
     *
     * @return array{disk: string, isolated: bool, configured: bool, encrypted: bool, reason: string|null}
     */
    public function status(): array
    {
        $configuredDisk = (string) config('backup.disk');
        $effective = $this->diskName();
        $reason = null;

        if ($effective !== $configuredDisk) {
            $reason = "La boveda aislada '{$configuredDisk}' no esta configurada; se opera sobre '{$effective}'.";
        } elseif (! $this->encryptionEnabled()) {
            $reason = 'El cifrado en reposo esta desactivado o sin BACKUP_ENCRYPTION_KEY.';
        }

        return [
            'disk' => $effective,
            'isolated' => $effective === $configuredDisk && str_starts_with($effective, 'gcs'),
            'configured' => $effective === $configuredDisk,
            'encrypted' => $this->encryptionEnabled(),
            'reason' => $reason,
        ];
    }

    // -----------------------------------------------------------------
    // Boveda
    // -----------------------------------------------------------------

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Disco efectivo. Si la boveda aislada no tiene credenciales, se degrada
     * al disco local antes que dejar al sistema sin respaldo — pero el estado
     * degradado se reporta en `status()` y en la salida de los comandos.
     */
    public function diskName(): string
    {
        $configured = (string) config('backup.disk');

        if ($this->vaultConfigured($configured)) {
            return $configured;
        }

        return (string) config('backup.fallback_disk', 'backups_local');
    }

    private function vaultConfigured(string $disk): bool
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return false;
        }

        if (($config['driver'] ?? null) !== 'gcs') {
            // Discos no-GCS (local, s3, o un fake de pruebas) se usan tal cual.
            return true;
        }

        return filled($config['bucket'] ?? null)
            && (filled($config['key_file_path'] ?? null) || filled($config['key_file_json'] ?? null));
    }

    private function manifestPath(string $name): string
    {
        return $name.'.manifest.json';
    }

    private function upload(string $artifact, BackupManifest $manifest): void
    {
        $disk = $this->disk();

        // Streaming: un dump de varios cientos de MB no cabe en memoria.
        $stream = fopen($artifact, 'rb');

        try {
            $disk->writeStream($manifest->artifact, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // El manifiesto se escribe DESPUES del artefacto: si la subida del
        // artefacto falla, el snapshot no aparece en el catalogo y nadie
        // intentara restaurar desde un punto incompleto.
        $disk->put($this->manifestPath($manifest->name), $manifest->toJson());
    }

    private function download(BackupManifest $manifest): string
    {
        $disk = $this->disk();

        if (! $disk->exists($manifest->artifact)) {
            throw new RuntimeException("El artefacto '{$manifest->artifact}' no esta en la boveda.");
        }

        $local = $this->tempPath($manifest->artifact);
        $source = $disk->readStream($manifest->artifact);
        $target = fopen($local, 'wb');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $local;
    }

    // -----------------------------------------------------------------
    // PostgreSQL
    // -----------------------------------------------------------------

    private function dumpDatabase(string $target): void
    {
        $db = config('database.connections.pgsql');

        $command = [
            config('backup.pg_dump_path', 'pg_dump'),
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            '--dbname='.$db['database'],
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            // --clean --if-exists deja un dump idempotente: puede aplicarse
            // sobre una base con datos sin colisionar con objetos existentes.
            '--clean',
            '--if-exists',
            '--file='.$target,
        ];

        $result = Process::timeout((int) config('backup.dump_timeout_seconds', 1800))
            ->env($this->pgEnvironment($db))
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump fallo: '.trim($result->errorOutput() ?: $result->output()));
        }

        if (! File::exists($target) || File::size($target) === 0) {
            throw new RuntimeException('pg_dump termino sin error pero no genero contenido.');
        }
    }

    /**
     * Restauracion TRANSACCIONAL. Es el requisito duro del rollback:
     *
     *   --single-transaction  envuelve todo el dump en un BEGIN/COMMIT.
     *   ON_ERROR_STOP=1       aborta al primer error en lugar de seguir
     *                         aplicando sentencias sobre un estado a medias.
     *
     * Juntos garantizan el todo-o-nada: si algo falla, PostgreSQL revierte y
     * la base queda exactamente como estaba antes del intento.
     */
    private function restoreDatabase(string $dump): void
    {
        $db = config('database.connections.pgsql');

        $command = [
            config('backup.psql_path', 'psql'),
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            '--dbname='.$db['database'],
            '--single-transaction',
            '--variable=ON_ERROR_STOP=1',
            '--quiet',
            '--file='.$dump,
        ];

        $result = Process::timeout((int) config('backup.restore.statement_timeout_seconds', 900))
            ->env($this->pgEnvironment($db))
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                'La restauracion fallo y PostgreSQL revirtio la transaccion completa. '
                .'La base de datos NO fue modificada. Detalle: '
                .trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * La contraseña viaja por entorno, nunca como argumento: los argumentos
     * son visibles en la tabla de procesos para cualquier usuario del host.
     *
     * @param  array<string, mixed>  $db
     * @return array<string, string>
     */
    private function pgEnvironment(array $db): array
    {
        return [
            'PGPASSWORD' => (string) ($db['password'] ?? ''),
            'PGSSLMODE' => (string) ($db['sslmode'] ?? 'prefer'),
        ];
    }

    // -----------------------------------------------------------------
    // Empaquetado y cifrado
    // -----------------------------------------------------------------

    private function compress(string $workspace, string $name): string
    {
        $archive = $this->tempPath($name.'.tar.gz');

        $result = Process::timeout((int) config('backup.dump_timeout_seconds', 1800))
            ->run(['tar', '-czf', $archive, '-C', $workspace, '.']);

        if (! $result->successful()) {
            throw new RuntimeException('No se pudo comprimir el snapshot: '.trim($result->errorOutput()));
        }

        return $archive;
    }

    private function extract(string $archive, string $workspace): void
    {
        $result = Process::timeout((int) config('backup.dump_timeout_seconds', 1800))
            ->run(['tar', '-xzf', $archive, '-C', $workspace]);

        if (! $result->successful()) {
            throw new RuntimeException('No se pudo extraer el snapshot: '.trim($result->errorOutput()));
        }
    }

    /**
     * Cifrado AES-256-CBC con derivacion PBKDF2 (100k iteraciones) y sal
     * aleatoria. Se delega en el binario `openssl` para poder cifrar en
     * streaming: `openssl_encrypt()` de PHP exige cargar el archivo entero en
     * memoria, inviable con un dump de varios cientos de MB.
     *
     * La llave viaja por entorno (`pass env:`), nunca como argumento.
     */
    private function encrypt(string $archive): string
    {
        if (! $this->encryptionEnabled()) {
            return $archive;
        }

        $this->assertBinary('openssl', 'openssl');

        $target = $archive.'.enc';

        $result = Process::timeout((int) config('backup.dump_timeout_seconds', 1800))
            ->env(['CRONOS_BACKUP_KEY' => $this->encryptionKey()])
            ->run([
                'openssl', 'enc',
                '-'.config('backup.encryption.cipher', 'aes-256-cbc'),
                '-pbkdf2', '-iter', '100000', '-salt',
                '-in', $archive,
                '-out', $target,
                '-pass', 'env:CRONOS_BACKUP_KEY',
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('El cifrado del snapshot fallo: '.trim($result->errorOutput()));
        }

        File::delete($archive);

        return $target;
    }

    private function decrypt(string $artifact, BackupManifest $manifest): string
    {
        if (! $manifest->encrypted) {
            return $artifact;
        }

        $this->assertBinary('openssl', 'openssl');

        if (blank($this->encryptionKey())) {
            throw new RuntimeException(
                'El snapshot esta cifrado pero BACKUP_ENCRYPTION_KEY no esta configurada. '
                .'Sin la llave original el artefacto es irrecuperable.'
            );
        }

        $target = $this->tempPath($manifest->name.'.decrypted.tar.gz');

        $result = Process::timeout((int) config('backup.dump_timeout_seconds', 1800))
            ->env(['CRONOS_BACKUP_KEY' => $this->encryptionKey()])
            ->run([
                'openssl', 'enc', '-d',
                '-'.($manifest->cipher ?: 'aes-256-cbc'),
                '-pbkdf2', '-iter', '100000',
                '-in', $artifact,
                '-out', $target,
                '-pass', 'env:CRONOS_BACKUP_KEY',
            ]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'No se pudo descifrar el snapshot. Verifica que BACKUP_ENCRYPTION_KEY sea la '
                .'misma con la que se genero. Detalle: '.trim($result->errorOutput())
            );
        }

        return $target;
    }

    private function encryptionEnabled(): bool
    {
        return (bool) config('backup.encryption.enabled', true) && filled($this->encryptionKey());
    }

    private function encryptionKey(): string
    {
        return (string) config('backup.encryption.key', '');
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function copyConfiguration(string $workspace): array
    {
        $copied = [];

        foreach ((array) config('backup.include.config_paths', []) as $relative) {
            $source = base_path($relative);

            if (! File::isDirectory($source)) {
                continue;
            }

            File::ensureDirectoryExists($workspace.'/'.$relative);
            File::copyDirectory($source, $workspace.'/'.$relative);
            $copied[] = $relative.'/';
        }

        if (config('backup.include.env_file', false) && File::exists(base_path('.env'))) {
            File::copy(base_path('.env'), $workspace.'/env.backup');
            $copied[] = 'env.backup';
        }

        return $copied;
    }

    private function buildMetadata(string $name, string $trigger, ?User $actor): string
    {
        return json_encode([
            'snapshot' => $name,
            'trigger' => $trigger,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $actor?->email ?? 'system',
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'database' => config('database.connections.pgsql.database'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function generateName(string $trigger): string
    {
        return sprintf(
            'cronos-pos-%s-%s-%s',
            now()->format('Ymd-His'),
            Str::slug($trigger) ?: 'manual',
            Str::lower(Str::random(6))
        );
    }

    private function makeWorkspace(string $name): string
    {
        $path = storage_path('app/'.self::WORK_DIR.'/'.Str::slug($name).'-'.Str::random(6));

        File::ensureDirectoryExists($path);

        // El dump viaja en claro dentro del workspace: solo el propietario.
        @chmod($path, 0700);

        return $path;
    }

    private function tempPath(string $filename): string
    {
        $dir = storage_path('app/'.self::WORK_DIR);
        File::ensureDirectoryExists($dir);
        @chmod($dir, 0700);

        return $dir.'/'.basename($filename);
    }

    private function cleanup(string $workspace): void
    {
        if (File::isDirectory($workspace)) {
            File::deleteDirectory($workspace);
        }
    }

    private function assertBinary(string $binary, string $label): void
    {
        $result = Process::run(['sh', '-c', 'command -v '.escapeshellarg($binary)]);

        if (! $result->successful()) {
            throw new RuntimeException(
                "El binario '{$label}' no esta disponible en este contenedor. "
                .'Agrega el paquete correspondiente a la imagen (ver backend/Dockerfile.prod).'
            );
        }
    }

    /**
     * Escribe una entrada en la auditoria de seguridad.
     *
     * Doble destino a proposito: la tabla `audit_logs` para la consulta
     * forense habitual, y el log de aplicacion (que vive en el sistema de
     * archivos, FUERA de la base de datos) como copia que ningun rollback
     * puede sobrescribir.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function audit(string $action, ?User $actor, array $metadata): void
    {
        $row = [
            'action' => $action,
            'auditable_type' => 'backup',
            'auditable_id' => (string) Str::uuid(),
            'user_id' => $actor?->id,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500),
            'created_at' => now(),
        ];

        // La traza en disco se escribe SIEMPRE y primero: es la unica que
        // sobrevive a una restauracion de la propia tabla de auditoria.
        Log::channel(config('logging.default'))->info("[Auditoria] {$action}", $metadata + [
            'actor' => $actor?->email ?? 'system',
        ]);

        $this->trail[] = $row;

        try {
            AuditLog::create($row);
        } catch (Throwable $e) {
            // La auditoria no debe impedir un rollback de emergencia, pero su
            // ausencia si debe quedar registrada en el log de la aplicacion.
            Log::error('[Backup] No se pudo escribir la auditoria.', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reinyecta en `audit_logs` la traza acumulada durante la operacion en
     * curso. Se invoca justo despues de una restauracion exitosa, cuando el
     * dump acaba de reemplazar la tabla y borro las entradas previas.
     */
    private function replayTrail(): void
    {
        foreach ($this->trail as $row) {
            try {
                // array_merge, no `+`: el operador de union conserva la clave
                // existente y la marca de reinyeccion nunca llegaria a escribirse.
                AuditLog::create(array_merge($row, [
                    'metadata' => array_merge($row['metadata'], [
                        'replayed_after_restore' => true,
                    ]),
                ]));
            } catch (Throwable $e) {
                Log::error('[Backup] No se pudo reinyectar la auditoria tras el rollback.', [
                    'action' => $row['action'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
