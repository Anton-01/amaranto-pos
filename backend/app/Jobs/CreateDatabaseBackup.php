<?php

namespace App\Jobs;

use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generacion de un punto de restauracion en segundo plano (Fase 10).
 *
 * Un `pg_dump` completo puede tardar minutos: ejecutarlo dentro de la peticion
 * HTTP agotaria el timeout de Nginx. Encolarlo ademas hace que el respaldo
 * aparezca automaticamente en el panel de telemetria de jobs — las dos mitades
 * de esta fase se observan la una a la otra.
 */
class CreateDatabaseBackup implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo reintento: si el dump falla, casi siempre es por credenciales,
     * disco lleno o boveda inalcanzable. Insistir cinco veces solo alarga el
     * diagnostico y multiplica artefactos a medio subir.
     */
    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly ?string $actorId = null,
        public readonly string $trigger = 'manual',
    ) {}

    public function handle(BackupService $backups): void
    {
        $actor = $this->actorId ? User::find($this->actorId) : null;

        $manifest = $backups->create($actor, $this->trigger);

        SystemNotification::notifyAdmins(SystemNotification::TYPE_BACKUP_COMPLETED, [
            'snapshot' => $manifest->name,
            'checksum' => $manifest->checksum,
            'size_bytes' => $manifest->size_bytes,
            'size_human' => $manifest->humanSize(),
            'encrypted' => $manifest->encrypted,
            'disk' => $backups->diskName(),
            'isolated' => $backups->status()['isolated'],
            'trigger' => $this->trigger,
            'created_by' => $manifest->created_by_name,
        ]);
    }

    /**
     * Un respaldo que falla en silencio es peor que no tener respaldo: el
     * operador cree estar cubierto. El fallo se notifica de forma visible
     * ademas de quedar en la telemetria de jobs.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('[Backup] El job de respaldo fallo definitivamente.', [
            'trigger' => $this->trigger,
            'error' => $exception->getMessage(),
        ]);

        SystemNotification::notifyAdmins(SystemNotification::TYPE_BACKUP_FAILED, [
            'trigger' => $this->trigger,
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
