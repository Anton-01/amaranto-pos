<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\CashClosingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierre automatico de cajas — programado todos los dias a las 21:00
 * (America/Mexico_City) en routes/console.php.
 *
 * Escanea toda caja abierta sin arqueo —incluidas las rezagadas de dias
 * anteriores que un cajero olvido cerrar— y ejecuta el cierre bajo la
 * identidad "System Automated Process". Cada caja se cierra en su propia
 * transaccion con lockForUpdate (dentro de CashClosingService::close), de modo
 * que un fallo en una caja no bloquea el cierre de las demas, y un cierre
 * manual concurrente pierde o gana la carrera limpiamente, nunca duplica.
 *
 * El registro generado es un ledger insert-only: el modelo
 * CashRegisterClosing bloquea todo update y delete.
 */
class AutoCloseCashRegisters extends Command
{
    protected $signature = 'cronos:auto-close-registers
                            {--dry-run : Lista las cajas que se cerrarian sin ejecutar el cierre}';

    protected $description = 'Cierra automaticamente las cajas abiertas sin arqueo bajo el usuario System Automated Process';

    public function handle(CashClosingService $closingService): int
    {
        $openRegisters = CashRegister::with('user:id,name,email')
            ->whereNull('closed_at')
            ->whereDoesntHave('closing')
            ->orderBy('opened_at')
            ->get();

        if ($openRegisters->isEmpty()) {
            $this->info('Sin cajas abiertas pendientes de cierre.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($openRegisters as $register) {
                $this->line(sprintf(
                    '[dry-run] %s — %s (abierta %s)',
                    strtoupper(substr($register->id, 0, 8)),
                    $register->user?->name ?? 'N/D',
                    $register->opened_at?->timezone('America/Mexico_City')->format('d/m/Y H:i')
                ));
            }
            return self::SUCCESS;
        }

        $systemUser = User::systemProcess();

        $closed = [];
        $failed = 0;

        foreach ($openRegisters as $register) {
            try {
                $closing = $closingService->close(
                    cashRegister: $register,
                    closedBy: $systemUser,
                    declarations: null,
                    automated: true,
                    notes: 'Cierre automatico programado (21:00). Montos declarados no verificados fisicamente: el sistema asume declarado = esperado. Requiere conciliacion del efectivo al siguiente turno.',
                );

                $closed[] = [
                    'closing_id' => $closing->id,
                    'cash_register_id' => $register->id,
                    'register_folio' => strtoupper(substr($register->id, 0, 8)),
                    'operator_id' => $register->user_id,
                    'operator_name' => $register->user?->name ?? 'N/D',
                    'opened_at' => $register->opened_at?->toIso8601String(),
                    'opening_balance' => (float) $register->opening_balance,
                    'expected_amount' => (float) $closing->expected_amount,
                    'declared_amount' => (float) $closing->declared_amount,
                    'difference_amount' => (float) $closing->difference_amount,
                    'was_stale' => $register->opened_at !== null
                        && $register->opened_at->timezone('America/Mexico_City')->toDateString()
                            !== now('America/Mexico_City')->toDateString(),
                ];

                $this->info(sprintf(
                    'Cerrada %s de %s — esperado $%s',
                    strtoupper(substr($register->id, 0, 8)),
                    $register->user?->name ?? 'N/D',
                    number_format((float) $closing->expected_amount, 2)
                ));
            } catch (\RuntimeException $e) {
                // Carrera perdida contra un cierre manual simultaneo: no es un
                // error, la caja simplemente ya quedo cerrada por su cajero.
                if (str_starts_with($e->getMessage(), 'ERR_REGISTER_ALREADY_CLOSED')) {
                    $this->warn(sprintf('Omitida %s: cerrada manualmente durante la ejecucion.', strtoupper(substr($register->id, 0, 8))));
                    continue;
                }

                $failed++;
                Log::error('Auto-cierre de caja fallido', [
                    'cash_register_id' => $register->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('Fallo el cierre de %s: %s', strtoupper(substr($register->id, 0, 8)), $e->getMessage()));
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Auto-cierre de caja fallido', [
                    'cash_register_id' => $register->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('Fallo el cierre de %s: %s', strtoupper(substr($register->id, 0, 8)), $e->getMessage()));
            }
        }

        if ($closed !== []) {
            // Snapshot inmutable del evento: la notificacion cuenta lo que paso
            // a las 21:00 aunque las tablas vivas cambien despues.
            SystemNotification::notifyAdmins(SystemNotification::TYPE_AUTO_CASH_CLOSING, [
                'executed_at' => now()->toIso8601String(),
                'executed_by' => 'System Automated Process',
                'registers_closed' => count($closed),
                'registers_failed' => $failed,
                'total_expected' => round(array_sum(array_column($closed, 'expected_amount')), 2),
                'total_declared' => round(array_sum(array_column($closed, 'declared_amount')), 2),
                'total_difference' => round(array_sum(array_column($closed, 'difference_amount')), 2),
                'closings' => $closed,
            ]);
        }

        $this->info(sprintf('Resumen: %d cerradas, %d fallidas.', count($closed), $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
