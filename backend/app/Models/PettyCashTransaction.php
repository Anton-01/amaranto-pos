<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'amount',
        'reason',
        'description',
        'immutable_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'immutable_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('PettyCashTransaction records are immutable and cannot be updated.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifyIntegrity(): bool
    {
        $snapshot = $this->immutable_snapshot;
        if (! $snapshot || ! isset($snapshot['security_seal'])) {
            return false;
        }

        $dataString = implode('|', [
            $snapshot['operator_id'],
            $snapshot['operator_name'],
            $snapshot['amount'],
            $snapshot['reason'],
            $snapshot['description'],
            $snapshot['balance_before'],
            $snapshot['balance_after'],
            $snapshot['timestamp'],
        ]);

        $expectedSeal = hash('sha256', $dataString . config('app.key'));

        return hash_equals($expectedSeal, $snapshot['security_seal']);
    }
}
