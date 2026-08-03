<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Catalogo de puntos de restauracion disponibles en la boveda (Fase 10).
 *
 * Con `--verify` descarga cada artefacto y recalcula su SHA-256. Es una
 * operacion cara —transfiere todos los snapshots— pero es la unica forma
 * honesta de responder "¿mis respaldos sirven?" antes de necesitarlos.
 */
class BackupList extends Command
{
    protected $signature = 'cronos:backup-list
                            {--verify : Descarga cada snapshot y valida su checksum}
                            {--json : Salida en JSON}';

    protected $description = 'Lista los puntos de restauracion almacenados en la boveda de respaldos';

    public function handle(BackupService $backups): int
    {
        $status = $backups->status();
        $snapshots = $backups->listSnapshots();

        if ($this->option('json')) {
            $this->line(json_encode([
                'vault' => $status,
                'snapshots' => array_map(fn ($m) => $m->toArray(), $snapshots),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Boveda: {$status['disk']}".($status['isolated'] ? ' (aislada en GCP)' : ' (NO aislada)'));

        if ($status['reason']) {
            $this->warn('AVISO: '.$status['reason']);
        }

        if ($snapshots === []) {
            $this->newLine();
            $this->warn('No hay puntos de restauracion. Genera uno con: php artisan cronos:backup-run');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($snapshots as $manifest) {
            $integrity = '—';

            if ($this->option('verify')) {
                try {
                    $integrity = $backups->verify($manifest->name)['valid'] ? 'VALIDO' : 'CORRUPTO';
                } catch (Throwable $e) {
                    $integrity = 'ERROR';
                }
            }

            $rows[] = [
                $manifest->name,
                $manifest->created_at,
                $manifest->humanSize(),
                $manifest->encrypted ? 'Si' : 'NO',
                $manifest->trigger,
                $manifest->created_by_name ?? '—',
                $integrity,
            ];
        }

        $this->newLine();
        $this->table(
            ['Snapshot', 'Creado', 'Tamaño', 'Cifrado', 'Origen', 'Autor', 'Integridad'],
            $rows
        );

        return self::SUCCESS;
    }
}
