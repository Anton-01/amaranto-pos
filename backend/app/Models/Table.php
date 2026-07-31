<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Table extends Model
{
    use HasUuids;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_OCCUPIED,
        self::STATUS_RESERVED,
    ];

    protected $table = 'tables';

    protected $fillable = [
        'name',
        'capacity',
        'zone',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->timezone('America/Mexico_City')->toIso8601String();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    /**
     * Sesion viva de la mesa.
     *
     * El indice unico parcial `table_sessions_one_open_per_table` garantiza que
     * a lo sumo exista una, por lo que la relacion puede ser un hasOne simple.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)->where('table_sessions.status', TableSession::STATUS_OPEN);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
