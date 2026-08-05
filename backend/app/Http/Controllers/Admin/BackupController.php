<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateDatabaseBackup;
use App\Models\SystemNotification;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Administracion de la boveda de respaldos y del rollback manual (Fase 10).
 *
 * TODAS las rutas de este controlador viven bajo `role:admin`. Un manager
 * puede cerrar cajas y purgar la papelera, pero no puede reescribir la base
 * de datos completa.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * Catalogo de puntos de restauracion + diagnostico de la boveda.
     */
    public function index(): JsonResponse
    {
        try {
            $snapshots = $this->backups->listSnapshots();
        } catch (Throwable $e) {
            // Boveda inalcanzable: el panel debe DECIRLO, no mostrar una lista
            // vacia que se lee como "todo en orden, sin respaldos aun".
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_BACKUP_VAULT_UNREACHABLE',
                'message' => 'No se pudo consultar la boveda de respaldos: '.$e->getMessage(),
                'errors' => [],
                'metadata' => ['vault' => $this->backups->status()],
            ], 503);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'vault' => $this->backups->status(),
                'restore_phrase' => (string) config('backup.restore.require_confirmation_phrase'),
                'pre_restore_snapshot' => (bool) config('backup.restore.pre_restore_snapshot', true),
                'retention_days' => (int) config('backup.retention_days', 30),
                'snapshots' => array_map(
                    fn ($manifest) => $manifest->toArray() + ['size_human' => $manifest->humanSize()],
                    $snapshots
                ),
            ],
        ]);
    }

    /**
     * Dispara la generacion de un punto de restauracion.
     *
     * Se ENCOLA siempre: un pg_dump de produccion tarda minutos y agotaria el
     * timeout de Nginx. El avance se sigue en /admin/jobs-monitor.
     */
    public function store(Request $request): JsonResponse
    {
        CreateDatabaseBackup::dispatch($request->user()->id, 'manual');

        return response()->json([
            'status' => 'success',
            'code' => 'BACKUP_QUEUED',
            'message' => 'Respaldo encolado. Sigue su avance en el monitor de jobs.',
            'data' => ['vault' => $this->backups->status()],
        ], 202);
    }

    /**
     * Validacion de integridad bajo demanda: descarga el artefacto y
     * recalcula su SHA-256 contra el manifiesto, sin restaurar nada.
     */
    public function verify(string $snapshot): JsonResponse
    {
        try {
            $result = $this->backups->verify($snapshot);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_BACKUP_VERIFY_FAILED',
                'message' => $e->getMessage(),
                'errors' => [],
                'metadata' => null,
            ], 404);
        }

        return response()->json([
            'status' => $result['valid'] ? 'success' : 'error',
            'code' => $result['valid'] ? 'BACKUP_INTEGRITY_VALID' : 'ERR_BACKUP_INTEGRITY_COMPROMISED',
            'message' => $result['valid']
                ? 'El artefacto conserva su integridad: el checksum coincide con el manifiesto.'
                : 'INTEGRIDAD COMPROMETIDA: el checksum no coincide. Este snapshot no es confiable.',
            'data' => $result,
        ], $result['valid'] ? 200 : 422);
    }

    /**
     * ROLLBACK MANUAL. Sobrescribe la base de datos con un snapshot anterior.
     *
     * El servicio valida el checksum y genera un respaldo del estado actual
     * ANTES de escribir; si cualquiera de esos dos pasos falla, la base no se
     * toca. La restauracion en si es transaccional (todo-o-nada).
     */
    public function restore(Request $request, string $snapshot): JsonResponse
    {
        $validated = $request->validate([
            'confirmation_phrase' => 'required|string|max:120',
        ]);

        try {
            $result = $this->backups->restore(
                $snapshot,
                $request->user(),
                $validated['confirmation_phrase']
            );
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_BACKUP_RESTORE_FAILED',
                'message' => $e->getMessage(),
                'errors' => [],
                'metadata' => ['snapshot' => $snapshot],
            ], 422);
        }

        SystemNotification::notifyAdmins(SystemNotification::TYPE_BACKUP_RESTORED, [
            'snapshot' => $result['snapshot'],
            'safety_snapshot' => $result['safety_snapshot'],
            'duration_ms' => $result['duration_ms'],
            'restored_by' => $request->user()->name,
            'restored_by_email' => $request->user()->email,
            'channel' => 'panel',
        ]);

        return response()->json([
            'status' => 'success',
            'code' => 'BACKUP_RESTORED',
            'message' => 'Restauracion completada. La base de datos volvio al punto seleccionado.',
            'data' => $result,
        ]);
    }
}
