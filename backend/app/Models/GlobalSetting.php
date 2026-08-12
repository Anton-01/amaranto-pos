<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $table = 'global_settings';

    /**
     * Keys that hold secrets and must never travel through the generic
     * settings endpoints. They are stripped from every read and rejected on
     * write, so the only way to change them is their own dedicated endpoint,
     * which hashes the value before storing it.
     */
    public const PROTECTED_KEYS = ['cancellation_authorization'];

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
