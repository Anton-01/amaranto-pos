<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'version',
        'is_active',
        'business_name',
        'rfc',
        'address',
        'phone',
        'header_message',
        'footer_message',
        'logo_url',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
