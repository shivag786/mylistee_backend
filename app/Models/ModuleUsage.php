<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One billable unit of a per-use module (ModuleUsageService). */
class ModuleUsage extends Model
{
    protected $fillable = ['business_id', 'module', 'unit', 'period', 'reference', 'rate'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }
}
