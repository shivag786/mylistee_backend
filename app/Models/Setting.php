<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform key-value setting (document/phase/14 §Platform Settings). The scalar
 * value is wrapped as {"value": …} so a single JSON column holds any type.
 * Read/write through {@see \App\Services\SettingService}.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
