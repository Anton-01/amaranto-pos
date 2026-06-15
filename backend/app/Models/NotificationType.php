<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationType extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'allowed_roles',
    ];

    protected function casts(): array
    {
        return [
            'allowed_roles' => 'array',
        ];
    }

    public function userPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }
}
