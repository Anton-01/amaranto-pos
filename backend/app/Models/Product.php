<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'current_stock' => 'integer',
            'minimum_stock' => 'integer',
            'maximum_stock' => 'integer',
            'is_active' => 'boolean',
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
}
