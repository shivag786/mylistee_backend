<?php

namespace App\Models;

use App\Enums\RewardStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Reward extends Model
{
    /** @use HasFactory<\Database\Factories\RewardFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'business_id',
        'offer_id',
        'code',
        'title',
        'reward_value',
        'type',
        'status',
        'won_at',
        'expires_at',
        'redeemed_at',
        'redeemed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RewardStatus::class,
            'won_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Reward $reward): void {
            if (empty($reward->uuid)) {
                $reward->uuid = (string) Str::uuid();
            }
            if (empty($reward->code)) {
                $reward->code = self::generateCode();
            }
        });
    }

    /** Human-friendly, unambiguous redemption code (no 0/O/1/I). */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** True when the reward can still be redeemed. */
    public function isRedeemable(): bool
    {
        return $this->status === RewardStatus::Active && ! $this->isExpired();
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /** Flip still-active-but-past-expiry rewards to Expired (lazy sweep). */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', RewardStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now());
    }
}
