<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TableSession extends Model
{
    use HasUuids;

    /** Explicito: el modelo expone una relacion llamada table(). */
    protected $table = 'table_sessions';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'table_id',
        'order_id',
        'user_id',
        'closed_by',
        'guests',
        'notes',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'guests' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->timezone('America/Mexico_City')->toIso8601String();
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Mesero que abrio la mesa. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Usuario que ejecuto el cobro y libero la mesa. */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
