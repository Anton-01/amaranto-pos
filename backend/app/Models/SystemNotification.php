<?php

namespace App\Models;

use App\Support\Timezone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notificacion estructurada por tag.
 *
 * `type` es la etiqueta de renderizado: el frontend la evalua para elegir la
 * plantilla visual de la modal de detalles. `data` es un snapshot JSON
 * inmutable del evento — se congela al crearse y nunca se reconstruye desde
 * las tablas vivas, para que la notificacion siga contando la verdad aunque
 * los datos de origen cambien despues.
 *
 * Contrato de inmutabilidad: la unica columna que puede mutar tras el insert
 * es `read_at` (el acuse de lectura). Cualquier otro update, y todo delete,
 * lo bloquea el guard de booted().
 */
class SystemNotification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Cierre automatico de cajas ejecutado por el scheduler de las 21:00. */
    public const TYPE_AUTO_CASH_CLOSING = 'auto_cash_closing';

    /** Punto de restauracion generado y depositado en la boveda (Fase 10). */
    public const TYPE_BACKUP_COMPLETED = 'backup_completed';

    /** Fallo la generacion de un respaldo: el sistema quedo sin cobertura. */
    public const TYPE_BACKUP_FAILED = 'backup_failed';

    /** Rollback de emergencia ejecutado sobre la base de datos (Fase 10). */
    public const TYPE_BACKUP_RESTORED = 'backup_restored';

    protected $fillable = [
        'user_id',
        'type',
        'data',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->timezone(Timezone::app())->toIso8601String();
    }

    protected static function booted(): void
    {
        static::updating(function (self $notification) {
            $dirty = array_keys($notification->getDirty());

            if (array_diff($dirty, ['read_at'])) {
                throw new \RuntimeException(
                    'ERR_NOTIFICATION_IMMUTABLE: Solo el acuse de lectura (read_at) puede modificarse en una notificacion.'
                );
            }
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'ERR_NOTIFICATION_IMMUTABLE: Las notificaciones del sistema no pueden eliminarse.'
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Emite la misma notificacion a todos los administradores activos.
     *
     * @return int Numero de notificaciones creadas.
     */
    public static function notifyAdmins(string $type, array $data): int
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->where('status', 'active')
            ->get();

        foreach ($admins as $admin) {
            static::create([
                'user_id' => $admin->id,
                'type' => $type,
                'data' => $data,
                'created_at' => now(),
            ]);
        }

        return $admins->count();
    }
}
