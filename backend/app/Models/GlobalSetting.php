<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalSetting extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $table = 'global_settings';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
