<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterClosing extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'cash_register_id',
        'closed_by',
        'expected_amount',
        'declared_amount',
        'difference_amount',
        'payment_breakdown',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException(
                'ERR_CLOSING_IMMUTABLE: Los registros de cierre de caja son inmutables y no pueden modificarse.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'ERR_CLOSING_IMMUTABLE: Los registros de cierre de caja son inmutables y no pueden eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'expected_amount'  => 'decimal:2',
            'declared_amount'  => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'payment_breakdown' => 'array',
            'created_at'       => 'datetime',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
