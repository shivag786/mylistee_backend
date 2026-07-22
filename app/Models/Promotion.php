<?php

namespace App\Models;

use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A promotion in the one engine (Phase 7.2b, 07A). Pricing rules read from
 * `config`; the object knows whether it is currently active and how much it
 * discounts a given base price.
 */
class Promotion extends Model
{
    /** @use HasFactory<\Database\Factories\PromotionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'product_id',
        'promotion_type',
        'name',
        'config',
        'status',
        'starts_at',
        'ends_at',
        'daily_start_time',
        'daily_end_time',
        'auto_start',
        'auto_stop',
        'priority',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'promotion_type' => PromotionType::class,
            'status' => PromotionStatus::class,
            'config' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'auto_start' => 'boolean',
            'auto_stop' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Promotion $promotion): void {
            if (empty($promotion->uuid)) {
                $promotion->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Is the promotion live right now? Requires Running status, an in-range date
     * window, and (for happy-hour style promotions) the current time inside the
     * daily window.
     */
    public function isActiveNow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($this->status !== PromotionStatus::Running) {
            return false;
        }
        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return $this->withinDailyWindow($now);
    }

    /** True when no daily window is set, or `now` falls inside it. */
    public function withinDailyWindow(Carbon $now): bool
    {
        if ($this->daily_start_time === null || $this->daily_end_time === null) {
            return true;
        }

        $current = $now->format('H:i:s');
        $start = $this->normaliseTime($this->daily_start_time);
        $end = $this->normaliseTime($this->daily_end_time);

        // Same-day window (e.g. 15:00–18:00). Overnight windows aren't supported yet.
        return $current >= $start && $current <= $end;
    }

    /**
     * How much this promotion reduces `$base` (a unit price). Cart-level types
     * (BOGO / quantity discount) don't change unit price and return 0.
     */
    public function discountAmount(float $base): float
    {
        if (! $this->promotion_type->affectsUnitPrice()) {
            return 0.0;
        }

        $config = $this->config ?? [];
        $type = $config['discount_type'] ?? 'percentage';
        $value = (float) ($config['value'] ?? 0);

        $discount = $type === 'flat' ? $value : $base * ($value / 100);

        return (float) max(0, min($discount, $base));
    }

    private function normaliseTime(string $time): string
    {
        // Accept "H:i" or "H:i:s"; compare as "H:i:s".
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
