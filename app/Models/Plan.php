<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A subscription plan definition (document/phase/02 §Subscriptions). Limits and
 * capabilities are data, not code — the Super Admin edits them (Milestone 14).
 */
class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'price',
        'currency',
        'interval',
        'max_active_offers',
        'max_offer_days',
        'max_qr_codes',
        'max_gallery_images',
        'features',
        'badge',
        'is_public',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'max_active_offers' => 'integer',
            'max_offer_days' => 'integer',
            'max_qr_codes' => 'integer',
            'max_gallery_images' => 'integer',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Plan $plan): void {
            if (empty($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }
        });
    }

    /** The default (free) plan every business falls back to. */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? static::query()->orderBy('sort_order')->first();
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /** @param  Builder<Plan>  $query */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->orderBy('sort_order');
    }
}
