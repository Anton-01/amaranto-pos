<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSessionLog extends Model
{
    use HasUuids;

    protected $table = 'user_sessions_log';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'login_at',
        'logout_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
