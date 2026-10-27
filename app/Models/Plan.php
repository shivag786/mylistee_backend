<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
        'duration_days',
        'duration_months',
        'days_from',
        'modules',
        'max_products',
        'intro_price',
        'intro_days',
        'intro_ends_at',
        'intro_label',
        'max_active_offers',
        'max_active_combos',
        'max_active_promotions',
        'max_push_per_month',
        'push_reach_per_month',
        'push_per_customer_per_month',
        'customer_details',
        'max_offer_days',
        'max_qr_codes',
        'max_gallery_images',
        'features',
        'badge',
        'is_public',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'modules' => 'array',
            'duration_days' => 'integer',
            'duration_months' => 'integer',
            'days_from' => 'datetime',
            'max_products' => 'integer',
            'intro_price' => 'decimal:2',
            'intro_days' => 'integer',
            'intro_ends_at' => 'datetime',
            'max_active_offers' => 'integer',
            'max_active_combos' => 'integer',
            'max_active_promotions' => 'integer',
            'max_push_per_month' => 'integer',
            'push_reach_per_month' => 'integer',
            'push_per_customer_per_month' => 'integer',
            'max_offer_days' => 'integer',
            'max_qr_codes' => 'integer',
            'max_gallery_images' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
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

    /**
     * A capability check. Store mode and customer push now live in the module
     * map; plans not yet edited keep reading the old feature list.
     */
    public function hasFeature(string $key): bool
    {
        $module = ['store_mode' => 'store_mode', 'push_notifications' => 'notifications'][$key] ?? null;
        if ($module !== null && $this->modules !== null) {
            return $this->moduleConfig($module)['on'];
        }

        return in_array($key, $this->features ?? [], true);
    }

    /**
     * Whether this plan includes a module, and for how many days (null = the
     * whole plan period). A plan whose modules were never set keeps the old
     * behaviour: everything on, except store mode and push, which followed the
     * feature list.
     *
     * @return array{on: bool, days: int|null}
     */
    public function moduleConfig(string $module): array
    {
        if ($this->modules === null) {
            $legacy = ['store_mode' => 'store_mode', 'notifications' => 'push_notifications'][$module] ?? null;

            return ['on' => $legacy === null || in_array($legacy, $this->features ?? [], true), 'days' => null];
        }

        $entry = $this->modules[$module] ?? null;
        if (is_bool($entry)) {
            return ['on' => $entry, 'days' => null];
        }

        $days = $entry['days'] ?? null;

        return [
            'on' => (bool) ($entry['on'] ?? false),
            'days' => $days === null || $days === '' ? null : max(1, (int) $days),
        ];
    }

    /**
     * When one period of this plan, starting at $from, ends: the admin's days or
     * months, else the billing interval. Null = never (lifetime).
     */
    public function periodEnd(Carbon $from): ?Carbon
    {
        if ($this->duration_months !== null) {
            return $from->copy()->addMonthsNoOverflow($this->duration_months);
        }
        if ($this->duration_days !== null) {
            return $from->copy()->addDays($this->duration_days);
        }

        return match ($this->interval) {
            'quarter' => $from->copy()->addMonths(3),
            'year' => $from->copy()->addYear(),
            'lifetime' => null,
            default => $from->copy()->addMonth(),
        };
    }

    /** The plan has its own length (days or months) instead of the interval. */
    public function hasDuration(): bool
    {
        return $this->duration_days !== null || $this->duration_months !== null;
    }

    /** Shops see customers' email and phone in full (else masked). */
    public function showsFullCustomerDetails(): bool
    {
        return $this->customer_details === 'full';
    }

    /** The first-time offer is set and still open. */
    public function introActive(): bool
    {
        return $this->intro_price !== null
            && $this->intro_days !== null
            && ($this->intro_ends_at === null || $this->intro_ends_at->isFuture());
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /** @param  Builder<Plan>  $query */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true)->orderBy('sort_order');
    }
}
