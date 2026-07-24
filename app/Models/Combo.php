<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A combo bundle (Phase 7.3). Total MRP + savings are computed from the member
 * products so they never drift out of sync with product prices.
 */
class Combo extends Model
{
    /** @use HasFactory<\Database\Factories\ComboFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'product_category_id',
        'name',
        'image_path',
        'combo_price',
        'coins_earned',
        'wallet_coins_accepted',
        'coins_accepted',
        'next_visit_coupon',
        'bonus_reward',
        'starts_at',
        'ends_at',
        'auto_enable',
        'auto_disable',
        'is_visible',
        'position',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'combo_price' => 'decimal:2',
            'wallet_coins_accepted' => 'boolean',
            'coins_accepted' => 'integer',
            'auto_enable' => 'boolean',
            'auto_disable' => 'boolean',
            'is_visible' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Combo $combo): void {
            if (empty($combo->uuid)) {
                $combo->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<ComboItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class);
    }

    /** Sum of member products' selling price × quantity (uses loaded items). */
    public function totalPrice(): float
    {
        return (float) $this->items->sum(
            fn (ComboItem $item) => (float) ($item->product?->selling_price ?? 0) * $item->quantity,
        );
    }

    /** Sum of member products' MRP × quantity, falling back to selling price. */
    public function totalMrp(): float
    {
        return (float) $this->items->sum(function (ComboItem $item): float {
            $unit = $item->product?->mrp ?? $item->product?->selling_price ?? 0;

            return (float) $unit * $item->quantity;
        });
    }

    /** What the customer saves vs buying the items separately. */
    public function savings(): float
    {
        return (float) max(0, round($this->totalPrice() - (float) $this->combo_price, 2));
    }

    /** Whether the combo is on right now (visible + within its schedule). */
    public function isActiveNow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if (! $this->is_visible) {
            return false;
        }
        if ($this->auto_enable && $this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->auto_disable && $this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}
