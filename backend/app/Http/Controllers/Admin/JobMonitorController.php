<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobExecutionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Panel de telemetria de jobs en segundo plano (Fase 10) — solo admin.
 *
 * Tres vistas sobre la misma bitacora:
 *   index()   Catalogo: un renglon por TIPO de job, con su salud agregada.
 *   history() Histórico forense: un renglon por INTENTO, paginado y filtrable.
 *   retry()   Reintento manual de un intento fallido.
 */
class JobMonitorController extends Controller
{
    /**
     * Catalogo de jobs conocidos por el sistema y su estado actual.
     *
     * Se agrega sobre el histórico —no sobre un registro estatico— para que
     * el catalogo refleje lo que el sistema REALMENTE ejecuta, incluidos los
     * Mailables y Notifications encolados que nadie declara como "job".
     */
    public function index(): JsonResponse
    {
        $catalog = JobExecutionLog::query()
            ->select('job_name')
            ->selectRaw('COUNT(*) AS total_runs')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'success') AS success_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'failed') AS failed_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'pending') AS pending_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'running') AS running_count")
            ->selectRaw('MAX(created_at) AS last_seen_at')
            ->selectRaw('ROUND(AVG(duration_ms) FILTER (WHERE status = \'success\')) AS avg_duration_ms')
            ->selectRaw('MAX(duration_ms) FILTER (WHERE status = \'success\') AS max_duration_ms')
            ->groupBy('job_name')
            ->orderByDesc('last_seen_at')
            ->get();

        // Estatus del ultimo intento de cada job: una tasa de exito historica
        // del 99% no sirve de nada si la corrida de anoche fallo.
        $latestStatuses = $this->latestStatusPerJob($catalog->pluck('job_name')->all());

        $jobs = $catalog->map(function ($row) use ($latestStatuses) {
            $total = (int) $row->total_runs;
            $failed = (int) $row->failed_count;
            $success = (int) $row->success_count;
            $finished = $success + $failed;

            return [
                'job_name' => $row->job_name,
                'short_name' => class_basename($row->job_name),
                'total_runs' => $total,
                'success_count' => $success,
                'failed_count' => $failed,
                'pending_count' => (int) $row->pending_count,
                'running_count' => (int) $row->running_count,
                'success_rate' => $finished > 0 ? round(($success / $finished) * 100, 1) : null,
                'avg_duration_ms' => $row->avg_duration_ms !== null ? (int) $row->avg_duration_ms : null,
                'max_duration_ms' => $row->max_duration_ms !== null ? (int) $row->max_duration_ms : null,
                // selectRaw evita el casteo de Eloquent: PostgreSQL devuelve
                // "2026-08-02 21:06:10+00", que no es ISO 8601 estricto y que
                // Safari rechaza en `new Date()`. Se normaliza aqui.
                'last_seen_at' => $row->last_seen_at ? Carbon::parse($row->last_seen_at)->toIso8601String() : null,
                'last_status' => $latestStatuses[$row->job_name] ?? null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'jobs' => $jobs,
                'summary' => $this->summary(),
            ],
        ]);
    }

    /**
     * Histórico paginado de ejecuciones con filtros por estatus, job y fecha.
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', JobExecutionLog::STATUSES),
            'job_name' => 'nullable|string|max:255',
            'trigger_source' => 'nullable|string|in:user,system,console,scheduler',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $logs = JobExecutionLog::query()
            ->with('triggeredBy:id,name,email')
            ->status($validated['status'] ?? null)
            ->jobName($validated['job_name'] ?? null)
            ->when(
                $validated['trigger_source'] ?? null,
                fn ($q, $source) => $q->where('trigger_source', $source)
            )
            ->when(
                $validated['date_from'] ?? null,
                fn ($q, $from) => $q->where('created_at', '>=', $from)
            )
            ->when(
                $validated['date_to'] ?? null,
                // El filtro "hasta" debe incluir el dia completo, no cortarlo
                // a las 00:00 de esa fecha.
                fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay())
            )
            ->when(
                $validated['search'] ?? null,
                fn ($q, $search) => $q->where(function ($sub) use ($search) {
                    $sub->where('job_name', 'ilike', "%{$search}%")
                        ->orWhere('display_name', 'ilike', "%{$search}%")
                        ->orWhere('exception_message', 'ilike', "%{$search}%");
                })
            )
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 'success',
            'data' => $logs->through(fn (JobExecutionLog $log) => $this->transform($log)),
        ]);
    }

    /**
     * Detalle de un intento, con la traza tecnica completa para la modal.
     */
    public function show(JobExecutionLog $job): JsonResponse
    {
        $job->load('triggeredBy:id,name,email');

        return response()->json([
            'status' => 'success',
            'data' => $this->transform($job) + [
                'exception_trace' => $job->exception_trace,
                'context' => $job->context,
            ],
        ]);
    }

    /**
     * Reintento manual de un job fallido.
     *
     * Delega en `queue:retry`, que reconstruye el payload original desde la
     * tabla `failed_jobs` y lo vuelve a empujar. No se reconstruye el job a
     * mano: el payload serializado es la unica copia fiel de sus argumentos.
     */
    public function retry(JobExecutionLog $job): JsonResponse
    {
        if ($job->status !== JobExecutionLog::STATUS_FAILED) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_JOB_NOT_FAILED',
                'message' => 'Solo puede reintentarse un job en estado fallido.',
                'errors' => [],
                'metadata' => ['current_status' => $job->status],
            ], 422);
        }

        if (! $job->isRetryable()) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_JOB_ALREADY_RETRIED',
                'message' => 'Este job ya volvio a ejecutarse despues del intento fallido seleccionado.',
                'errors' => [],
                'metadata' => null,
            ], 409);
        }

        // `failed_jobs` es la fuente del payload: sin fila ahi, no hay nada
        // que reintentar (el job pudo haber sido purgado con queue:flush).
        $failedExists = DB::table('failed_jobs')->where('uuid', $job->job_uuid)->exists();

        if (! $failedExists) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_JOB_PAYLOAD_UNAVAILABLE',
                'message' => 'El payload original ya no esta en la cola de fallidos; no es posible reintentarlo.',
                'errors' => [],
                'metadata' => ['job_uuid' => $job->job_uuid],
            ], 410);
        }

        try {
            Artisan::call('queue:retry', ['id' => [$job->job_uuid]]);
        } catch (Throwable $e) {
            Log::error('[Telemetria] Fallo el reintento manual de un job.', [
                'job_uuid' => $job->job_uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_JOB_RETRY_FAILED',
                'message' => 'No se pudo reencolar el job: '.$e->getMessage(),
                'errors' => [],
                'metadata' => null,
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'code' => 'JOB_RETRY_QUEUED',
            'message' => 'El job fue reencolado. Su nuevo intento aparecera en el historico.',
            'data' => [
                'job_uuid' => $job->job_uuid,
                'job_name' => $job->job_name,
                'output' => trim(Artisan::output()),
            ],
        ]);
    }

    /**
     * Salud global de la cola para las tarjetas del encabezado.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $last24h = now()->subDay();

        $counts = JobExecutionLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'success' AND created_at >= ?) AS success_24h", [$last24h])
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'failed' AND created_at >= ?) AS failed_24h", [$last24h])
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'pending') AS pending", [])
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'running') AS running", [])
            ->first();

        return [
            'success_24h' => (int) $counts->success_24h,
            'failed_24h' => (int) $counts->failed_24h,
            'pending' => (int) $counts->pending,
            'running' => (int) $counts->running,
            'retention_days' => (int) config('telemetry.jobs.retention_days', 30),
            'telemetry_enabled' => (bool) config('telemetry.jobs.enabled', true),
        ];
    }

    /**
     * Estatus del intento mas reciente de cada job.
     *
     * @param  list<string>  $jobNames
     * @return array<string, string>
     */
    private function latestStatusPerJob(array $jobNames): array
    {
        if ($jobNames === []) {
            return [];
        }

        // DISTINCT ON de PostgreSQL: una sola pasada indexada por
        // (job_name, created_at DESC), sin subconsulta correlacionada.
        $rows = DB::table('job_execution_logs')
            ->select('job_name', 'status', 'created_at')
            ->whereIn('job_name', $jobNames)
            ->orderBy('job_name')
            ->orderByDesc('created_at')
            ->distinct('job_name')
            ->get();

        return $rows->pluck('status', 'job_name')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(JobExecutionLog $log): array
    {
        return [
            'id' => $log->id,
            'job_uuid' => $log->job_uuid,
            'job_name' => $log->job_name,
            'short_name' => $log->shortName(),
            'display_name' => $log->display_name,
            'status' => $log->status,
            'attempt' => $log->attempt,
            'connection' => $log->connection,
            'queue' => $log->queue,
            'queued_at' => $log->queued_at,
            'started_at' => $log->started_at,
            'finished_at' => $log->finished_at,
            'duration_ms' => $log->duration_ms,
            'exception_class' => $log->exception_class,
            'exception_message' => $log->exception_message,
            'has_trace' => filled($log->exception_trace),
            'trigger_source' => $log->trigger_source,
            'triggered_by' => $log->triggeredBy ? [
                'id' => $log->triggeredBy->id,
                'name' => $log->triggeredBy->name,
                'email' => $log->triggeredBy->email,
            ] : null,
            'retryable' => $log->status === JobExecutionLog::STATUS_FAILED && $log->isRetryable(),
            'created_at' => $log->created_at,
        ];
    }
}
