<?php

namespace App\Models;

use App\Support\ModuleCache;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Refresh policy of one cacheable module.
 *
 * One row per `module_name`: it says for how many minutes that module's
 * payload may be served from Redis and whether caching applies at all.
 * Nothing is read at boot — App\Support\ModuleCache resolves the policy right
 * before answering a request, so a duration changed in the admin UI takes
 * effect on the very next hit without restarting PHP-FPM.
 */
class CacheConfiguration extends Model
{
    use HasUuids;

    /** Executive KPI strip of the dashboard (month sales, orders, ticket, stock). */
    public const MODULE_DASHBOARD_STATS = 'dashboard_stats';

    /** Today's sales curve, grouped by local hour. */
    public const MODULE_DASHBOARD_HOURLY = 'dashboard_hourly_trend';

    /** Best selling products of the running month. */
    public const MODULE_DASHBOARD_TOP_PRODUCTS = 'dashboard_top_products';

    /** Full financial analytics modal (totals, comparison, trends). */
    public const MODULE_MONTHLY_ANALYTICS = 'monthly_analytics';

    /** Grouped product catalog consumed by the POS grid. */
    public const MODULE_PRODUCT_CATALOG = 'product_catalog';

    /**
     * Catalog of cacheable modules.
     *
     * Shared by the validation rules and by the admin UI dropdown so both ends
     * always offer the exact same set of keys.
     */
    public const MODULES = [
        self::MODULE_DASHBOARD_STATS => 'Dashboard — Métricas Ejecutivas',
        self::MODULE_DASHBOARD_HOURLY => 'Dashboard — Tendencia por Hora',
        self::MODULE_DASHBOARD_TOP_PRODUCTS => 'Dashboard — Top de Productos',
        self::MODULE_MONTHLY_ANALYTICS => 'Analítica Financiera Mensual',
        self::MODULE_PRODUCT_CATALOG => 'Catálogo de Productos (POS)',
    ];

    /**
     * Refresh windows offered by the UI, in minutes.
     *
     * Deliberately a closed list: an arbitrary TTL typed by hand is how a
     * dashboard ends up frozen for a week, so the options stop at two days.
     */
    public const DURATION_OPTIONS = [
        15 => '15 minutos',
        30 => '30 minutos',
        60 => '1 hora',
        1440 => '1 día',
        2880 => '2 días',
    ];

    /**
     * Duration applied when a module has no row yet.
     *
     * Short on purpose: an unconfigured module should feel almost live, and
     * the administrator widens the window only where staleness is acceptable.
     */
    public const DEFAULT_DURATION_MINUTES = 15;

    /**
     * Where the resolved policy map lives in Redis.
     *
     * The whole table is one small array, so it is cached as a single entry
     * and every module lookup reads from memory: the dynamic cache must not
     * cost one PostgreSQL query per request to decide whether to cache.
     */
    public const POLICY_CACHE_KEY = 'cache_configurations:policies';

    /** How long the policy map itself survives; writes invalidate it eagerly. */
    private const POLICY_CACHE_TTL = 3600;

    protected $fillable = [
        'module_name',
        'duration_minutes',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Any write to a policy makes two things stale at once: the cached
         * policy map, and every payload already stored under the old window.
         * Both are dropped here so the next request rebuilds with the new
         * duration instead of serving data under a policy that no longer
         * exists — shortening a TTL must take effect immediately, not when
         * the previous (longer) window happens to expire.
         */
        static::saved(static function (self $configuration): void {
            Cache::forget(self::POLICY_CACHE_KEY);
            ModuleCache::flush($configuration->module_name);
        });

        static::deleted(static function (self $configuration): void {
            Cache::forget(self::POLICY_CACHE_KEY);
            ModuleCache::flush($configuration->module_name);
        });
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Policy map keyed by module, read from Redis and rebuilt from PostgreSQL
     * only when missing.
     *
     * @return array<string, array{duration_minutes: int, is_active: bool}>
     */
    public static function policies(): array
    {
        return Cache::remember(
            self::POLICY_CACHE_KEY,
            self::POLICY_CACHE_TTL,
            static fn (): array => static::query()
                ->select(['module_name', 'duration_minutes', 'is_active'])
                ->get()
                ->keyBy('module_name')
                ->map(fn (self $row): array => [
                    'duration_minutes' => $row->duration_minutes,
                    'is_active' => $row->is_active,
                ])
                ->all()
        );
    }

    /**
     * Refresh window of a module in minutes, or null when the module must not
     * be cached (policy disabled, or a nonsensical duration was stored).
     *
     * Callers treat null as "read live from PostgreSQL".
     */
    public static function durationFor(string $module): ?int
    {
        $policy = static::policies()[$module] ?? null;

        // No row yet: cache with the conservative default rather than leaving
        // the heaviest queries uncached until somebody visits the admin UI.
        if ($policy === null) {
            return self::DEFAULT_DURATION_MINUTES;
        }

        if (! $policy['is_active'] || $policy['duration_minutes'] < 1) {
            return null;
        }

        return $policy['duration_minutes'];
    }
}
