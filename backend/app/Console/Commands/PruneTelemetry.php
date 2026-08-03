<?php

namespace App\Console\Commands;

use App\Models\JobExecutionLog;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * Poda del histórico de telemetria y de la boveda de respaldos (Fase 10).
 *
 * Sin esto, `job_execution_logs` crece sin techo: cada correo encolado, cada
 * notificacion y cada respaldo dejan filas. Corre a diario desde el scheduler.
 */
class PruneTelemetry extends Command
{
    protected $signature = 'cronos:telemetry-prune
                            {--days= : Dias de retencion del historico de jobs}
                            {--backups : Purgar tambien los snapshots vencidos de la boveda}
                            {--dry-run : Muestra que se eliminaria sin borrar nada}';

    protected $description = 'Poda el historico de ejecucion de jobs y, opcionalmente, los respaldos vencidos';

    public function handle(BackupService $backups): int
    {
        $days = (int) ($this->option('days') ?? config('telemetry.jobs.retention_days', 30));
        $cutoff = now()->subDays($days);

        $query = JobExecutionLog::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Se eliminarian {$count} registros de telemetria anteriores a {$cutoff->toDateTimeString()}.");
        } else {
            // Borrado por lotes: un DELETE de cientos de miles de filas
            // mantendria un lock largo sobre la tabla que el worker necesita
            // escribir en cada transicion de estado.
            $deleted = 0;
            while (($chunk = $query->clone()->limit(5000)->pluck('id'))->isNotEmpty()) {
                $deleted += JobExecutionLog::whereIn('id', $chunk)->delete();
            }

            $this->info("Telemetria podada: {$deleted} registros anteriores a {$cutoff->toDateTimeString()}.");
        }

        if ($this->option('backups')) {
            if ($this->option('dry-run')) {
                $this->info('[dry-run] La poda de la boveda se omite en modo simulacion.');
            } else {
                $pruned = $backups->prune();

                $this->info($pruned === []
                    ? 'Boveda: sin snapshots vencidos.'
                    : 'Boveda: '.count($pruned).' snapshot(s) purgado(s) — '.implode(', ', $pruned));
            }
        }

        return self::SUCCESS;
    }
}
