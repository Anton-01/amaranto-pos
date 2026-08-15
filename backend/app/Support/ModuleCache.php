<?php

namespace App\Support;

use App\Models\CacheConfiguration;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Read-through cache whose TTL is decided by the database, not by the code.
 *
 * WHAT — every heavy read path (dashboard KPIs, hourly trend, top products,
 * monthly analytics, POS catalog) goes through `remember()` instead of calling
 * Cache::remember() with a literal duration. The duration comes from the
 * `cache_configurations` row of that module, so an administrator widens or
 * narrows the refresh window from the UI without touching PHP.
 *
 * HOW — three moving parts:
 *
 *  1. Resolution. `CacheConfiguration::durationFor()` returns the window in
 *     minutes, or null when the policy is switched off. Null short-circuits
 *     the whole mechanism: the callback runs against PostgreSQL and nothing is
 *     stored, which is the escape hatch when staleness is unacceptable.
 *
 *  2. Storage. Entries live under `module_cache:{module}:{key}` on the default
 *     store, which is Redis in every deployed environment (CACHE_STORE=redis).
 *     The `{key}` half is chosen by the caller and usually carries the day or
 *     the month being rendered, so a date rollover naturally lands on a fresh
 *     entry instead of serving yesterday's numbers.
 *
 *  3. Invalidation. Redis cache tags are deliberately NOT used: they are
 *     unsupported by the `database` store and would tie the feature to one
 *     driver. Instead every stored key is appended to a per-module index
 *     (`module_cache_index:{module}`), and `flush()` walks that index calling
 *     Cache::forget() on each entry. That is what lets a new sale drop the
 *     dashboard payload of "today" the instant it is registered.
 */
class ModuleCache
{
    /** Namespace of the cached payloads. */
    private const KEY_PREFIX = 'module_cache';

    /** Namespace of the per-module list of live keys. */
    private const INDEX_PREFIX = 'module_cache_index';

    /**
     * Upper bound of the key index.
     *
     * Date-scoped keys accumulate one entry per day, so the index is pruned
     * to keep it from growing without limit. Dropped keys simply expire on
     * their own TTL instead of being purged by `flush()`.
     */
    private const INDEX_MAX_KEYS = 200;

    /**
     * Serves `$callback` through the module's configured window.
     *
     * @template TValue
     *
     * @param  string  $module  One of CacheConfiguration::MODULES.
     * @param  string  $key  Variant discriminator (date, month, filters...).
     * @param  Closure(): TValue  $callback  The expensive query to memoize.
     * @return TValue
     */
    public static function remember(string $module, string $key, Closure $callback): mixed
    {
        $minutes = CacheConfiguration::durationFor($module);

        // Policy disabled: read live and store nothing.
        if ($minutes === null) {
            return $callback();
        }

        $cacheKey = self::key($module, $key);

        // Registered before the write so a concurrent flush() that lands
        // between both calls still sees the key it has to drop.
        self::registerKey($module, $cacheKey);

        return Cache::remember($cacheKey, $minutes * 60, $callback);
    }

    /**
     * Drops every payload stored for a module.
     *
     * Called from the models that own the underlying data (a new sale, a
     * cancellation, a stock change), so the next request rebuilds instead of
     * showing figures that the operator already knows are outdated.
     */
    public static function flush(string $module): void
    {
        $index = Cache::get(self::indexKey($module), []);

        foreach ((array) $index as $cacheKey) {
            Cache::forget($cacheKey);
        }

        Cache::forget(self::indexKey($module));
    }

    /** Drops the payloads of several modules in one call. */
    public static function flushMany(array $modules): void
    {
        foreach ($modules as $module) {
            self::flush($module);
        }
    }

    /**
     * Every module whose payload depends on the sales ledger.
     *
     * @return array<int, string>
     */
    public static function salesDependentModules(): array
    {
        return [
            CacheConfiguration::MODULE_DASHBOARD_STATS,
            CacheConfiguration::MODULE_DASHBOARD_HOURLY,
            CacheConfiguration::MODULE_DASHBOARD_TOP_PRODUCTS,
            CacheConfiguration::MODULE_MONTHLY_ANALYTICS,
        ];
    }

    private static function key(string $module, string $key): string
    {
        return self::KEY_PREFIX . ':' . $module . ':' . $key;
    }

    private static function indexKey(string $module): string
    {
        return self::INDEX_PREFIX . ':' . $module;
    }

    /**
     * Appends a key to the module index.
     *
     * The read-modify-write is not atomic; under a race two workers can end up
     * writing the same list. That is harmless: the worst outcome is a key
     * missing from the index, which then expires on its own TTL instead of
     * being forgotten early — never a stale entry that survives forever,
     * because every entry always carries the module's TTL.
     */
    private static function registerKey(string $module, string $cacheKey): void
    {
        $indexKey = self::indexKey($module);
        $index = (array) Cache::get($indexKey, []);

        if (in_array($cacheKey, $index, true)) {
            return;
        }

        $index[] = $cacheKey;

        if (count($index) > self::INDEX_MAX_KEYS) {
            $index = array_slice($index, -self::INDEX_MAX_KEYS);
        }

        Cache::forever($indexKey, array_values($index));
    }
}
