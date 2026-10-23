<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A discount a customer applies at checkout. Either a shop code anyone can use
 * (`kind` code) or one earned by a single customer (comeback / loyalty).
 */
class Coupon extends Model
{
    use SoftDeletes;

    public const KIND_CODE = 'code';

    public const KIND_COMEBACK = 'comeback';

    public const KIND_LOYALTY = 'loyalty';

    protected $fillable = [
        'business_id', 'customer_id', 'code', 'title', 'kind', 'tier_at', 'discount_type', 'value',
        'max_discount', 'min_bill', 'per_customer_limit', 'usage_limit', 'used_count',
        'starts_at', 'expires_at', 'revealed_at', 'active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_bill' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revealed_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Coupon $coupon): void {
            $coupon->uuid ??= (string) Str::uuid();
            $coupon->code = Str::upper(trim($coupon->code));
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Switched on, started, not expired, and not used up overall. */
    public function isLive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->active
            && ($this->starts_at === null || $now->gte($this->starts_at))
            && ($this->expires_at === null || $now->lte($this->expires_at))
            && ($this->usage_limit === null || $this->used_count < $this->usage_limit);
    }

    /** What this coupon takes off `$amount` (the minimum bill is checked by the caller). */
    public function discountFor(float $amount): float
    {
        $off = $this->discount_type === 'percent'
            ? $amount * min(100.0, (float) $this->value) / 100
            : (float) $this->value;
        if ($this->max_discount !== null) {
            $off = min($off, (float) $this->max_discount);
        }

        return round(min($off, $amount), 2);
    }

    /** "20% off up to ₹100", "₹50 off". */
    public function label(): string
    {
        return RewardRule::label($this->discount_type, (float) $this->value, $this->max_discount !== null ? (float) $this->max_discount : null);
    }
}
