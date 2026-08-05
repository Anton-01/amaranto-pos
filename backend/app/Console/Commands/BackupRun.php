<?php

namespace App\Console\Commands;

use App\Jobs\CreateDatabaseBackup;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera un punto de restauracion (Fase 10).
 *
 * Por defecto corre en linea (sincrono) para que el scheduler y el operador
 * vean el resultado inmediato; con `--queue` se delega al worker.
 */
class BackupRun extends Command
{
    protected $signature = 'cronos:backup-run
                            {--trigger=manual : Origen del respaldo (manual, scheduled, pre-restore)}
                            {--user= : UUID o email del usuario que lo solicita}
                            {--queue : Encolar en lugar de ejecutar en linea}';

    protected $description = 'Genera un snapshot cifrado de PostgreSQL + configuracion y lo deposita en la boveda aislada';

    public function handle(BackupService $backups): int
    {
        $actor = $this->resolveActor();
        $trigger = (string) $this->option('trigger');

        $status = $backups->status();

        if (! $status['isolated']) {
            $this->warn('AVISO: '.($status['reason'] ?? 'La boveda aislada en GCP no esta activa.'));
            $this->warn("Se usara el disco '{$status['disk']}'.");
        }

        if (! $status['encrypted']) {
            $this->warn('AVISO: el snapshot se generara SIN cifrar (falta BACKUP_ENCRYPTION_KEY).');
        }

        if ($this->option('queue')) {
            CreateDatabaseBackup::dispatch($actor?->id, $trigger);
            $this->info('Respaldo encolado. Sigue su avance en /admin/jobs-monitor.');

            return self::SUCCESS;
        }

        $this->info('Generando snapshot… (pg_dump + configuracion + cifrado + subida)');

        try {
            $manifest = $backups->create($actor, $trigger);
        } catch (Throwable $e) {
            $this->error('El respaldo fallo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Campo', 'Valor'], [
            ['Snapshot', $manifest->name],
            ['Artefacto', $manifest->artifact],
            ['Tamaño', $manifest->humanSize()],
            ['Checksum SHA-256', $manifest->checksum],
            ['Cifrado', $manifest->encrypted ? 'Si ('.$manifest->cipher.')' : 'NO'],
            ['Boveda', $backups->diskName()],
            ['Contenido', implode(', ', $manifest->contents)],
        ]);

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $identifier = $this->option('user');

        if (blank($identifier)) {
            return null;
        }

        // `id` es UUID en PostgreSQL: comparar una cadena arbitraria contra la
        // columna aborta la consulta con "invalid input syntax for type uuid".
        $actor = User::where(fn ($q) => Str::isUuid($identifier)
            ? $q->where('id', $identifier)
            : $q->where('email', $identifier))
            ->first();

        if (! $actor) {
            $this->warn("Usuario '{$identifier}' no encontrado; el respaldo se atribuira al sistema.");
        }

        return $actor;
    }
}
