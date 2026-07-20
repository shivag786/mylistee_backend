<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A feature flag toggled by the Super Admin without a deploy
 * (document/phase/14 §Feature Flags).
 */
class FeatureFlag extends Model
{
    protected $fillable = ['key', 'name', 'description', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** Whether a feature is on. Unknown flags fall back to $default. */
    public static function isEnabled(string $key, bool $default = true): bool
    {
        $value = static::query()->where('key', $key)->value('enabled');

        return $value === null ? $default : (bool) $value;
    }
}
