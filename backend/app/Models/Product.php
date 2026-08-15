<?php

namespace App\Models;

use App\Support\ModuleCache;
use App\Traits\AdvancedSoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuids, AdvancedSoftDeletes;

    protected $fillable = [
        'sku',
        'parent_sku',
        'name',
        'category_id',
        'cost_price',
        'sale_price',
        'current_stock',
        'minimum_stock',
        'maximum_stock',
        'is_active',
        'track_stock',
        'image_url',
    ];

    public function setSkuAttribute($value): void
    {
        $this->attributes['sku'] = strtoupper(trim($value));
    }

    public function setParentSkuAttribute($value): void
    {
        $this->attributes['parent_sku'] = $value ? strtoupper(trim($value)) : null;
    }

    protected function casts(): array
    {
        return [
            'cost_price'    => 'decimal:2',
            'sale_price'    => 'decimal:2',
            'current_stock' => 'integer',
            'minimum_stock' => 'integer',
            'maximum_stock' => 'integer',
            'is_active'     => 'boolean',
            'track_stock'   => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'product_promotion');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected static function booted(): void
    {
        /*
         * Critical invalidation of the catalog caches.
         *
         * WHAT — two cached payloads read this table: the POS product grid and
         * the "low stock alerts" counter of the dashboard. A price change, a
         * deactivation or a stock movement invalidates both.
         *
         * HOW — `saved` also fires on the `decrement('current_stock')` a sale
         * performs, which is exactly the point: the alert counter must react
         * to the unit that just crossed the minimum, not to the next TTL
         * expiry. Several decrements in one ticket cause several purges, and
         * that is fine — forgetting an already-forgotten key is a no-op.
         */
        static::saved(static fn () => self::flushCatalogCaches());
        static::deleted(static fn () => self::flushCatalogCaches());
    }

    private static function flushCatalogCaches(): void
    {
        ModuleCache::flushMany([
            CacheConfiguration::MODULE_PRODUCT_CATALOG,
            CacheConfiguration::MODULE_DASHBOARD_STATS,
        ]);
    }
}
