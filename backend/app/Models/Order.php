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
        'payment_method_id',
        'promotion_id',
        'discount_type',
        'discount_value',
        'discount_total',
        'subtotal',
        'iva_total',
        'total',
        'custom_legend',
        'amount_received',
        'amount_change',
        'status',
        'canceled_by',
        'canceled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'iva_total' => 'decimal:2',
            'total' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'amount_change' => 'decimal:2',
            'canceled_at' => 'datetime',
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

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function canceledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
