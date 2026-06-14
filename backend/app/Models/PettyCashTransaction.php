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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
