<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A reward tier a customer can spend Listee Coins on (Phase 2).
 */
class LoyaltyReward extends Model
{
    /** @use HasFactory<\Database\Factories\LoyaltyRewardFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'title',
        'description',
        'coins_cost',
        'reward_value',
        'active',
        'stock',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'coins_cost' => 'integer',
            'active' => 'boolean',
            'stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoyaltyReward $reward): void {
            if (empty($reward->uuid)) {
                $reward->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Redeemable now: active and either unlimited or with stock left. */
    public function isAvailable(): bool
    {
        return $this->active && ($this->stock === null || $this->stock > 0);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
