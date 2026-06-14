<?php

namespace App\Models;

use App\Traits\AdvancedSoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable, AdvancedSoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'password_restored_at',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'two_factor_confirmed_at' => 'datetime',
            'password_restored_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'model_has_roles', 'model_id', 'role_id');
    }

    public function sessionsLog(): HasMany
    {
        return $this->hasMany(UserSessionLog::class);
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function pettyCashTransactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }
}
