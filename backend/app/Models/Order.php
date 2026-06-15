<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'cash_register_id',
        'ticket_config_id',
        'payment_method',
        'subtotal',
        'iva_total',
        'total',
        'custom_legend',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'iva_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function ticketConfig(): BelongsTo
    {
        return $this->belongsTo(TicketConfig::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
