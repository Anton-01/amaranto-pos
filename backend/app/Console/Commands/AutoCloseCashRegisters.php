<?php

namespace App\Console\Commands;

use App\Jobs\SendConfiguredProcessMail;
use App\Mail\CashRegisterClosingReportMail;
use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\EmailConfiguration;
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

        /** @var array<int, array{closing: CashRegisterClosing, operator: string}> */
        $reportable = [];

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

                $reportable[] = [
                    'closing' => $closing,
                    'operator' => $register->user?->name ?? 'N/D',
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

        $this->dispatchClosingReports($reportable);

        $this->info(sprintf('Resumen: %d cerradas, %d fallidas.', count($closed), $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Hands the closing reports over to the queue.
     *
     * The command never touches SMTP: it only checks whether the "jobs"
     * process has an active mailbox and, if so, dispatches one queued
     * SendConfiguredProcessMail per closing. Credentials, sender identity,
     * subject and recipients are resolved later inside the worker, which keeps
     * a slow or unreachable provider from stretching the 21:00 window and from
     * turning a successful closing into a failed command.
     *
     * @param  array<int, array{closing: CashRegisterClosing, operator: string}>  $reportable
     */
    private function dispatchClosingReports(array $reportable): void
    {
        if ($reportable === []) {
            return;
        }

        // Single probe before the loop: with no active configuration there is
        // nothing to send and the queue is left untouched.
        if (EmailConfiguration::activeFor(EmailConfiguration::PROCESS_JOBS) === null) {
            $this->line('Notificacion por correo omitida: sin configuracion activa para el proceso "jobs".');

            return;
        }

        foreach ($reportable as $entry) {
            /** @var CashRegisterClosing $closing */
            $closing = $entry['closing'];

            SendConfiguredProcessMail::dispatch(
                EmailConfiguration::PROCESS_JOBS,
                new CashRegisterClosingReportMail(
                    closingId: $closing->id,
                    operatorName: $entry['operator'],
                    closingDate: ($closing->created_at ?? now())
                        ->timezone('America/Mexico_City')
                        ->format('d/m/Y H:i:s'),
                    totalAmount: (float) $closing->expected_amount,
                    paymentBreakdown: $this->breakdownForMail($closing),
                    isAutomated: true,
                )
            );
        }

        $this->info(sprintf('%d reporte(s) de cierre encolado(s) para envio por correo.', count($reportable)));
    }

    /**
     * Flattens the stored breakdown into the label => amount shape the mail
     * template renders. Only the registered amount is carried over: an
     * automated closing declares exactly what the system expected, so the
     * declared/difference columns of the ledger have nothing to add here.
     *
     * @return array<string, float>
     */
    private function breakdownForMail(CashRegisterClosing $closing): array
    {
        return collect($closing->payment_breakdown ?? [])
            ->mapWithKeys(fn (array $row) => [
                (string) ($row['name'] ?? 'Otro') => round((float) ($row['expected'] ?? 0), 2),
            ])
            ->all();
    }
}
