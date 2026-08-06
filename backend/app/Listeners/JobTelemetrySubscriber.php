<?php

namespace App\Listeners;

use App\Models\JobExecutionLog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Captura automatica de telemetria de jobs (Fase 10).
 *
 * Se engancha a los eventos NATIVOS de la cola, de modo que cualquier job,
 * Mailable o Notification con ShouldQueue queda auditado sin tocar su codigo.
 *
 * Ciclo de vida de una fila de `job_execution_logs`:
 *
 *   JobQueued     -> INSERT  status=pending, attempt=0, captura al disparador
 *   JobProcessing -> UPDATE  status=running, attempt=n, started_at
 *   JobProcessed  -> UPDATE  status=success, duration_ms
 *   JobFailed     -> UPDATE  status=failed,  traza de la excepcion
 *
 * `JobQueued` corre en el proceso que despacha (normalmente una peticion HTTP
 * con sesion), mientras que el resto corre en el worker. Por eso el usuario
 * disparador solo puede capturarse en el primero, y los siguientes se
 * correlacionan por el UUID que Laravel asigna al payload.
 *
 * REGLA DE ORO: la telemetria jamas debe tumbar el trabajo que observa. Todo
 * handler esta envuelto en try/catch; si la escritura falla, se registra en el
 * log de la aplicacion y el job sigue su curso.
 */
class JobTelemetrySubscriber
{
    /**
     * Cronometro monotonico en memoria, indexado por "uuid:intento".
     *
     * Es ESTATICO por necesidad: Laravel resuelve una instancia nueva del
     * suscriptor en cada evento, asi que el estado de instancia no sobrevive
     * de JobProcessing a JobProcessed.
     *
     * Se mide con un reloj de proceso en lugar de restar `started_at` releido
     * de PostgreSQL: ambos extremos salen de la misma fuente, sin viaje a la
     * base de datos de por medio ni dependencia de como se serialicen las
     * fechas. Nacio para esquivar un desfase de 6 h entre la zona de la
     * aplicacion y la de la sesion de PostgreSQL —ya corregido en
     * `config/database.php`— y se conserva porque sigue siendo la medicion
     * mas precisa.
     *
     * @var array<string, float>
     */
    private static array $timers = [];

    /**
     * OJO con los nombres de los metodos: Laravel auto-descubre en `app/Listeners`
     * todo metodo que empiece por `handle` y lo registra contra el evento que
     * declara su firma. Si estos se llamaran `handleQueued`, `handleProcessing`,
     * etc., quedarian registrados DOS veces —una por el descubrimiento y otra
     * por este subscribe()— y cada job escribiria su telemetria por duplicado.
     * El prefijo `record` mantiene el registro bajo control exclusivo de aqui,
     * que es lo que permite respetar el interruptor de configuracion.
     */
    public function subscribe(Dispatcher $events): void
    {
        if (! config('telemetry.jobs.enabled', true)) {
            return;
        }

        $events->listen(JobQueued::class, [self::class, 'recordQueued']);
        $events->listen(JobProcessing::class, [self::class, 'recordProcessing']);
        $events->listen(JobProcessed::class, [self::class, 'recordProcessed']);
        $events->listen(JobFailed::class, [self::class, 'recordFailed']);
    }

    public function recordQueued(JobQueued $event): void
    {
        $this->guard(function () use ($event) {
            $name = $this->resolveQueuedName($event);

            if ($this->ignored($name)) {
                return;
            }

            $uuid = $this->payloadUuid($event);

            JobExecutionLog::create([
                'job_uuid' => $uuid,
                'job_name' => $name,
                'display_name' => $this->payloadDisplayName($event) ?? class_basename($name),
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'attempt' => JobExecutionLog::ATTEMPT_QUEUED,
                'status' => JobExecutionLog::STATUS_PENDING,
                'queued_at' => now(),
                'triggered_by' => Auth::id(),
                'trigger_source' => $this->triggerSource(),
                'context' => array_filter([
                    'delay_seconds' => $event->delay,
                    'queue_id' => is_scalar($event->id) ? (string) $event->id : null,
                ], fn ($value) => $value !== null),
            ]);
        });
    }

    public function recordProcessing(JobProcessing $event): void
    {
        $this->guard(function () use ($event) {
            $name = $event->job->resolveName();

            if ($this->ignored($name)) {
                return;
            }

            $log = $this->openAttempt($event->job->uuid(), $event->job->attempts(), [
                'job_name' => $name,
                'display_name' => class_basename($name),
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
            ]);

            self::$timers[$this->timerKey($event->job->uuid(), $event->job->attempts())] = microtime(true);

            $log->forceFill([
                'status' => JobExecutionLog::STATUS_RUNNING,
                'attempt' => $event->job->attempts(),
                'started_at' => now(),
            ])->save();
        });
    }

    public function recordProcessed(JobProcessed $event): void
    {
        $this->guard(function () use ($event) {
            if ($this->ignored($event->job->resolveName())) {
                return;
            }

            $log = $this->currentAttempt($event->job->uuid(), $event->job->attempts());

            // Un job liberado con `release()` vuelve a la cola sin fallar:
            // no es un exito, seguira su propio intento posterior.
            if (! $log || $event->job->isReleased()) {
                return;
            }

            $this->close($log, JobExecutionLog::STATUS_SUCCESS);
        });
    }

    public function recordFailed(JobFailed $event): void
    {
        $this->guard(function () use ($event) {
            $name = $event->job->resolveName();

            if ($this->ignored($name)) {
                return;
            }

            $log = $this->currentAttempt($event->job->uuid(), $event->job->attempts())
                ?? $this->openAttempt($event->job->uuid(), $event->job->attempts(), [
                    'job_name' => $name,
                    'display_name' => class_basename($name),
                    'connection' => $event->connectionName,
                    'queue' => $event->job->getQueue(),
                ]);

            $exception = $event->exception;

            $log->forceFill([
                'exception_class' => $exception::class,
                'exception_message' => Str::limit((string) $exception->getMessage(), 2000),
                'exception_trace' => $this->trace($exception),
            ]);

            $this->close($log, JobExecutionLog::STATUS_FAILED);
        });
    }

    /**
     * Abre la fila del intento en curso.
     *
     * La fila `pending` creada al encolar (attempt = 0) se REUTILIZA para el
     * primer intento; cada reintento posterior estrena su propia fila, de modo
     * que el histórico conserva por que fallo cada pasada.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openAttempt(?string $uuid, int $attempt, array $attributes): JobExecutionLog
    {
        $existing = $this->currentAttempt($uuid, $attempt);

        if ($existing) {
            return $existing;
        }

        if ($uuid) {
            $queued = JobExecutionLog::where('job_uuid', $uuid)
                ->where('attempt', JobExecutionLog::ATTEMPT_QUEUED)
                ->first();

            if ($queued) {
                return $queued;
            }
        }

        // Sin fila previa: el job se despacho antes de activar la telemetria,
        // por una via que no emite JobQueued, o es un reintento manual.
        return JobExecutionLog::create($attributes + [
            'job_uuid' => $uuid,
            'attempt' => $attempt,
            'status' => JobExecutionLog::STATUS_RUNNING,
            'trigger_source' => 'system',
        ]);
    }

    private function currentAttempt(?string $uuid, int $attempt): ?JobExecutionLog
    {
        if (blank($uuid)) {
            return null;
        }

        return JobExecutionLog::where('job_uuid', $uuid)
            ->whereIn('attempt', [$attempt, JobExecutionLog::ATTEMPT_QUEUED])
            ->orderByDesc('attempt')
            ->first();
    }

    private function close(JobExecutionLog $log, string $status): void
    {
        $log->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => $this->elapsedMs($log),
        ])->save();
    }

    /**
     * Duracion del intento en milisegundos.
     *
     * Fuente primaria: el cronometro monotonico del proceso. Como respaldo
     * —worker reiniciado a media ejecucion— se recurre al valor CRUDO de la
     * columna. Desde que la sesion de PostgreSQL comparte zona con la
     * aplicacion, ese valor llega con su offset explicito y `Carbon::parse` lo
     * resuelve al instante correcto.
     */
    private function elapsedMs(JobExecutionLog $log): ?int
    {
        $key = $this->timerKey($log->job_uuid, (int) $log->attempt);

        if (isset(self::$timers[$key])) {
            $elapsed = (int) round((microtime(true) - self::$timers[$key]) * 1000);

            unset(self::$timers[$key]);

            return max(0, $elapsed);
        }

        $rawStart = $log->getRawOriginal('started_at');

        if (blank($rawStart)) {
            return null;
        }

        try {
            return max(0, (int) round(now()->diffInMilliseconds(Carbon::parse($rawStart), absolute: true)));
        } catch (Throwable) {
            return null;
        }
    }

    private function timerKey(?string $uuid, int $attempt): string
    {
        return ($uuid ?? 'sin-uuid').':'.$attempt;
    }

    /**
     * El UUID vive dentro del payload serializado; es la unica pieza que
     * viaja intacta entre el proceso que despacha y el worker.
     */
    private function payloadUuid(JobQueued $event): ?string
    {
        try {
            return $event->payload()['uuid'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadDisplayName(JobQueued $event): ?string
    {
        try {
            return $event->payload()['displayName'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `$event->job` puede ser una Closure encolada, un objeto o el nombre de
     * la clase; hay que normalizar los tres.
     */
    private function resolveQueuedName(JobQueued $event): string
    {
        if (is_object($event->job)) {
            return $event->job::class;
        }

        return is_string($event->job) ? $event->job : 'unknown';
    }

    private function triggerSource(): string
    {
        if (Auth::hasUser() || Auth::id()) {
            return 'user';
        }

        return app()->runningInConsole() ? 'console' : 'system';
    }

    private function trace(Throwable $exception): string
    {
        return Str::limit(
            $exception->getTraceAsString(),
            (int) config('telemetry.jobs.max_trace_length', 20000),
            ' […traza truncada]'
        );
    }

    private function ignored(string $jobName): bool
    {
        foreach ((array) config('telemetry.jobs.ignore', []) as $needle) {
            if (str_contains($jobName, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blindaje: la telemetria observa, nunca interfiere. Un fallo escribiendo
     * la bitacora no debe propagarse al job (ni marcar como fallido un job que
     * en realidad funciono).
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('[Telemetria] No se pudo registrar el evento de job.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
