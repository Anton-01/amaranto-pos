<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class PaymentMethod extends Model
{
    use HasUuids;

    /**
     * Vigencia del catalogo en cache (60 minutos). El catalogo se invalida
     * ademas de forma automatica en cualquier alta/edicion/baja (ver booted()),
     * por lo que el TTL solo actua como red de seguridad.
     */
    public const CACHE_TTL = 3600;

    public const CACHE_PREFIX = 'payment_methods:list:';

    /** Variantes de filtro cacheadas — son las unicas que acepta el endpoint. */
    public const CACHE_STATUSES = ['all', 'active', 'inactive'];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    protected static function booted(): void
    {
        static::saved(static fn () => static::flushCache());
        static::deleted(static fn () => static::flushCache());
    }

    /**
     * Catalogo de metodos de pago servido desde Redis.
     *
     * Se cachea el arreglo ya serializado (no la coleccion Eloquent) para que
     * la respuesta del POS no pague hidratacion de modelos ni golpee PostgreSQL.
     */
    public static function cachedList(string $status = 'all'): array
    {
        $status = in_array($status, self::CACHE_STATUSES, true) ? $status : 'all';

        return Cache::remember(
            self::CACHE_PREFIX . $status,
            self::CACHE_TTL,
            static function () use ($status): array {
                $query = static::query()->orderBy('name');

                if ($status !== 'all') {
                    $query->where('status', $status);
                }

                return $query->get()->toArray();
            }
        );
    }

    /** Invalida todas las variantes del catalogo cacheado. */
    public static function flushCache(): void
    {
        foreach (self::CACHE_STATUSES as $status) {
            Cache::forget(self::CACHE_PREFIX . $status);
        }
    }
}
