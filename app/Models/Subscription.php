<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A business's subscription to a {@see Plan} (document/phase/14). Managed by
 * {@see \App\Services\SubscriptionService}.
 */
class Subscription extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'plan_id',
        'status',
        'price',
        'currency',
        'interval',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'auto_renew',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription): void {
            if (empty($subscription->uuid)) {
                $subscription->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** @param  Builder<Subscription>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value);
    }
}
