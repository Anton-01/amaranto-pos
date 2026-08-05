<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de ejecucion de un INTENTO de job en segundo plano (Fase 10).
 *
 * Las filas las escribe App\Listeners\JobTelemetrySubscriber a partir de los
 * eventos nativos de la cola de Laravel; ningun job necesita instrumentarse
 * a mano.
 */
class JobExecutionLog extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    /** Marca de un intento que aun no ha llegado al worker. */
    public const ATTEMPT_QUEUED = 0;

    protected $fillable = [
        'job_uuid',
        'job_name',
        'display_name',
        'connection',
        'queue',
        'attempt',
        'status',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'exception_class',
        'exception_message',
        'exception_trace',
        'context',
        'triggered_by',
        'trigger_source',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Un intento que fallo y sigue siendo el ultimo de su job_uuid: es el
     * unico candidato legitimo a reintento manual. Reintentar un uuid que ya
     * volvio a correr duplicaria trabajo.
     */
    public function isRetryable(): bool
    {
        if ($this->status !== self::STATUS_FAILED || blank($this->job_uuid)) {
            return false;
        }

        return ! static::query()
            ->where('job_uuid', $this->job_uuid)
            ->where('attempt', '>', $this->attempt)
            ->exists();
    }

    /** Nombre corto para la interfaz: App\Jobs\FooBar -> FooBar. */
    public function shortName(): string
    {
        return class_basename($this->job_name);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeJobName(Builder $query, ?string $name): Builder
    {
        return $name ? $query->where('job_name', $name) : $query;
    }
}
