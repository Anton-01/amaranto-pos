<?php

namespace App\Console\Commands;

use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * ROLLBACK MANUAL — restauracion de emergencia (Fase 10).
 *
 * Comando deliberadamente incomodo de ejecutar. Sobrescribe la base de datos
 * de produccion, asi que exige TRES candados independientes:
 *
 *   1. Identidad: un usuario con rol admin debe firmar la operacion.
 *   2. Frase de confirmacion tecleada completa (no un simple s/n).
 *   3. Confirmacion interactiva adicional en entorno de produccion.
 *
 * La validacion de integridad por checksum y el respaldo de seguridad previo
 * los aplica BackupService::restore(); aqui solo se orquesta el dialogo humano.
 */
class BackupRestore extends Command
{
    protected $signature = 'cronos:backup-restore
                            {snapshot : Nombre del punto de restauracion (ver cronos:backup-list)}
                            {--user= : UUID o email del administrador que autoriza}
                            {--phrase= : Frase de confirmacion (se solicita si se omite)}
                            {--verify-only : Solo valida el checksum, sin restaurar}';

    protected $description = 'Restaura la base de datos a un punto anterior desde la boveda de respaldos';

    public function handle(BackupService $backups): int
    {
        $name = (string) $this->argument('snapshot');

        try {
            $manifest = $backups->findSnapshot($name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor'], [
            ['Snapshot', $manifest->name],
            ['Creado', $manifest->created_at],
            ['Base de datos', $manifest->database],
            ['Entorno origen', $manifest->app_env],
            ['Tamaño', $manifest->humanSize()],
            ['Cifrado', $manifest->encrypted ? 'Si ('.$manifest->cipher.')' : 'NO'],
            ['Checksum esperado', $manifest->checksum],
        ]);

        $this->newLine();
        $this->info('Validando integridad del artefacto…');

        try {
            $integrity = $backups->verify($name);
        } catch (Throwable $e) {
            $this->error('No se pudo validar el artefacto: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $integrity['valid']) {
            $this->error('INTEGRIDAD COMPROMETIDA — el checksum no coincide.');
            $this->line('  Esperado: '.$integrity['expected']);
            $this->line('  Obtenido: '.$integrity['actual']);
            $this->error('La restauracion queda bloqueada. Este snapshot NO es confiable.');

            return self::FAILURE;
        }

        $this->info('Checksum SHA-256 verificado correctamente.');

        if ($this->option('verify-only')) {
            return self::SUCCESS;
        }

        $actor = $this->resolveAdmin();

        if (! $actor) {
            return self::FAILURE;
        }

        if ($manifest->app_env !== config('app.env')) {
            $this->warn(sprintf(
                'ATENCION: el snapshot proviene del entorno "%s" y estas en "%s".',
                $manifest->app_env,
                config('app.env')
            ));
        }

        $this->newLine();
        $this->error('  Esta operacion SOBRESCRIBE la base de datos actual.  ');
        $this->line('  Base de datos destino: '.config('database.connections.pgsql.database'));

        if (config('backup.restore.pre_restore_snapshot', true)) {
            $this->line('  Antes de escribir se generara un respaldo del estado actual.');
        } else {
            $this->warn('  El respaldo previo esta DESACTIVADO: esta operacion sera irreversible.');
        }

        $phrase = $this->option('phrase') ?? $this->askForPhrase();

        if (blank($phrase)) {
            $this->warn('Restauracion cancelada.');

            return self::FAILURE;
        }

        // Cuarto candado: en produccion, confirmacion interactiva ademas de todo.
        if (app()->isProduction() && ! $this->option('no-interaction') && ! $this->confirm('Confirmas el rollback en PRODUCCION?', false)) {
            $this->warn('Restauracion cancelada.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ejecutando restauracion transaccional…');

        try {
            $result = $backups->restore($name, $actor, (string) $phrase);
        } catch (Throwable $e) {
            $this->error('ROLLBACK FALLIDO: '.$e->getMessage());

            return self::FAILURE;
        }

        SystemNotification::notifyAdmins(SystemNotification::TYPE_BACKUP_RESTORED, [
            'snapshot' => $result['snapshot'],
            'safety_snapshot' => $result['safety_snapshot'],
            'duration_ms' => $result['duration_ms'],
            'restored_by' => $actor->name,
            'restored_by_email' => $actor->email,
            'channel' => 'cli',
        ]);

        $this->newLine();
        $this->info('Restauracion completada en '.$result['duration_ms'].' ms.');

        if ($result['safety_snapshot']) {
            $this->line('Respaldo del estado previo: '.$result['safety_snapshot']);
        }

        return self::SUCCESS;
    }

    private function askForPhrase(): ?string
    {
        $expected = (string) config('backup.restore.require_confirmation_phrase');

        return $this->ask("Escribe exactamente «{$expected}» para continuar");
    }

    private function resolveAdmin(): ?User
    {
        $identifier = $this->option('user');

        if (blank($identifier)) {
            $this->error('Se requiere --user: toda restauracion queda firmada por un administrador.');

            return null;
        }

        // `id` es UUID en PostgreSQL: comparar una cadena arbitraria contra la
        // columna aborta la consulta con "invalid input syntax for type uuid".
        $actor = User::with('roles')
            ->where(fn ($q) => Str::isUuid($identifier)
                ? $q->where('id', $identifier)
                : $q->where('email', $identifier))
            ->first();

        if (! $actor) {
            $this->error("Usuario '{$identifier}' no encontrado.");

            return null;
        }

        if (! $actor->roles->contains('name', 'admin')) {
            $this->error("El usuario {$actor->email} no tiene rol admin. Solo un administrador puede autorizar un rollback.");

            return null;
        }

        return $actor;
    }
}
