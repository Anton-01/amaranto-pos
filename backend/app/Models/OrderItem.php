<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'promotion_id',
        'quantity',
        'base_price_at_sale',
        'discount_amount_at_sale',
        'final_price_at_sale',
        'tax_amount_at_sale',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'base_price_at_sale' => 'decimal:2',
            'discount_amount_at_sale' => 'decimal:2',
            'final_price_at_sale' => 'decimal:2',
            'tax_amount_at_sale' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
