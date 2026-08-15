<?php

namespace App\Models;

use App\Support\Timezone;
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
        // Sellado a mano (timestamps desactivados): marca el momento en que el
        // producto entro a la comanda y sostiene la trazabilidad por rondas.
        'created_at',
        // Stamped when a table cancellation voids the line. The amounts stay
        // untouched: a voided consumption is evidence, not garbage.
        'canceled_at',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return \Illuminate\Support\Carbon::instance($date)->timezone(Timezone::app())->toIso8601String();
    }

    protected function casts(): array
    {
        return [
            // Con timestamps desactivados el cast no es automatico, y sin el la
            // hora de comanda llegaria como string crudo.
            'created_at' => 'datetime',
            'canceled_at' => 'datetime',
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
