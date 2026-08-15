<?php

namespace App\Console\Commands;

use App\Jobs\SendConfiguredProcessMail;
use App\Mail\CashRegisterClosingReportMail;
use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\EmailConfiguration;
use App\Models\JobExecutionLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\CashClosingService;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
                            {--dry-run : Lista las cajas que se cerrarian sin ejecutar el cierre}
                            {--source=console : Origen del disparo para la bitacora: scheduler|console|system}';

    protected $description = 'Cierra automaticamente las cajas abiertas sin arqueo bajo el usuario System Automated Process';

    /**
     * Punto de entrada BLINDADO.
     *
     * Este comando corre a las 21:00 sin nadie mirando la consola, de modo que
     * un fallo aqui es, por definicion, un fallo silencioso: nadie se entera
     * hasta que a la mañana siguiente las cajas siguen abiertas. Por eso toda
     * la logica va envuelta en try/catch y deja rastro en `job_execution_logs`
     * —la misma bitacora que alimenta /admin/jobs-monitor—.
     *
     * La telemetria automatica de la Fase 10 (JobTelemetrySubscriber) NO cubre
     * este caso: escucha los eventos de la COLA, y un comando de consola nunca
     * los emite. De ahi que aqui se instrumente a mano.
     *
     * Contrato: pase lo que pase la fila queda cerrada en `success` o `failed`,
     * y ninguna excepcion escapa sin un `Log::error()`.
     */
    public function handle(CashClosingService $closingService): int
    {
        if ($this->option('dry-run')) {
            return $this->listPending();
        }

        // Cronometro monotonico: restar timestamps releidos de PostgreSQL
        // arrastraria el sesgo de zona horaria documentado en la Fase 10.
        $startedAt = microtime(true);
        $log = $this->openExecutionLog();

        try {
            $result = $this->closeOpenRegisters($closingService);

            $this->finishExecutionLog($log, JobExecutionLog::STATUS_SUCCESS, $startedAt, context: $result);

            return $result['registers_failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            // Nunca morir en silencio: la excepcion queda en la bitacora de BD
            // (con traza), en el log de aplicacion —que vive en el sistema de
            // archivos y sobrevive a un rollback— y en la salida del comando.
            $this->finishExecutionLog($log, JobExecutionLog::STATUS_FAILED, $startedAt, exception: $e);

            Log::error('[AutoCloseCashRegisters] El cierre automatico de cajas aborto.', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_execution_log_id' => $log?->id,
            ]);

            $this->error('El cierre automatico aborto: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Cierra toda caja abierta sin arqueo y notifica. Es el cuerpo que el
     * blindaje de `handle()` envuelve.
     *
     * @return array{registers_closed: int, registers_failed: int}
     */
    private function closeOpenRegisters(CashClosingService $closingService): array
    {
        $openRegisters = $this->pendingRegisters();

        if ($openRegisters->isEmpty()) {
            $this->info('Sin cajas abiertas pendientes de cierre.');

            return ['registers_closed' => 0, 'registers_failed' => 0];
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
                        && $register->opened_at->timezone(Timezone::app())->toDateString()
                            !== now()->toDateString(),
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

        return ['registers_closed' => count($closed), 'registers_failed' => $failed];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, CashRegister> */
    private function pendingRegisters()
    {
        return CashRegister::with('user:id,name,email')
            ->whereNull('closed_at')
            ->whereDoesntHave('closing')
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * `--dry-run`: solo lista. No abre bitacora porque no ejecuta nada — el
     * histórico forense debe contener ejecuciones reales, no simulacros.
     */
    private function listPending(): int
    {
        $openRegisters = $this->pendingRegisters();

        if ($openRegisters->isEmpty()) {
            $this->info('Sin cajas abiertas pendientes de cierre.');

            return self::SUCCESS;
        }

        foreach ($openRegisters as $register) {
            $this->line(sprintf(
                '[dry-run] %s — %s (abierta %s)',
                strtoupper(substr($register->id, 0, 8)),
                $register->user?->name ?? 'N/D',
                $register->opened_at?->timezone(Timezone::app())->format('d/m/Y H:i')
            ));
        }

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Telemetria (job_execution_logs)
    // -----------------------------------------------------------------

    /**
     * Abre la fila del intento en estado `running`.
     *
     * Se le asigna un `job_uuid` propio para respetar el indice unico
     * `(job_uuid, attempt)` y para que el panel pueda correlacionar la
     * ejecucion igual que la de cualquier job encolado. `attempt = 1`: un
     * comando de consola no pasa por la cola y no se reintenta solo.
     *
     * Si la escritura falla se devuelve null y el cierre CONTINUA: la
     * telemetria observa, nunca interfiere (regla de oro de la Fase 10).
     */
    private function openExecutionLog(): ?JobExecutionLog
    {
        try {
            return JobExecutionLog::create([
                'job_uuid' => (string) Str::uuid(),
                'job_name' => self::class,
                'display_name' => 'cronos:auto-close-registers',
                'connection' => 'sync',
                'queue' => 'scheduler',
                'attempt' => 1,
                'status' => JobExecutionLog::STATUS_RUNNING,
                'queued_at' => now(),
                'started_at' => now(),
                'trigger_source' => $this->triggerSource(),
                'context' => ['scheduled_at' => '21:00 America/Mexico_City'],
            ]);
        } catch (Throwable $e) {
            Log::error('[AutoCloseCashRegisters] No se pudo abrir la bitacora de ejecucion.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cierra la fila en `success` o `failed`.
     *
     * @param  array<string, mixed>  $context
     */
    private function finishExecutionLog(
        ?JobExecutionLog $log,
        string $status,
        float $startedAt,
        array $context = [],
        ?Throwable $exception = null,
    ): void {
        if ($log === null) {
            return;
        }

        try {
            $attributes = [
                'status' => $status,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'context' => array_merge((array) $log->context, $context),
            ];

            if ($exception !== null) {
                $attributes['exception_class'] = $exception::class;
                $attributes['exception_message'] = Str::limit($exception->getMessage(), 2000);
                $attributes['exception_trace'] = Str::limit(
                    $exception->getTraceAsString(),
                    (int) config('telemetry.jobs.max_trace_length', 20000),
                    ' […traza truncada]'
                );
            }

            $log->forceFill($attributes)->save();
        } catch (Throwable $e) {
            // Un fallo escribiendo la bitacora jamas debe enmascarar el
            // resultado real del cierre.
            Log::error('[AutoCloseCashRegisters] No se pudo cerrar la bitacora de ejecucion.', [
                'job_execution_log_id' => $log->id,
                'intended_status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function triggerSource(): string
    {
        $source = (string) $this->option('source');

        return in_array($source, ['scheduler', 'console', 'system', 'user'], true)
            ? $source
            : 'console';
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
                        ->timezone(Timezone::app())
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
