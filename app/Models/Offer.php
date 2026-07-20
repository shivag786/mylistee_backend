<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Offer extends Model
{
    /** @use HasFactory<\Database\Factories\OfferFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'title',
        'description',
        'type',
        'reward_value',
        'image_path',
        'starts_at',
        'ends_at',
        'total_quantity',
        'remaining_quantity',
        'weight',
        'priority',
        'status',
        'premium_only',
        'visibility',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'status' => OfferStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'premium_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Offer $offer): void {
            if (empty($offer->uuid)) {
                $offer->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Rewards won from this offer (for analytics — Milestone 12). @return HasMany<Reward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /** Is the offer currently live (active, in date window, in stock)? */
    public function isLive(): bool
    {
        if ($this->status !== OfferStatus::Active) {
            return false;
        }
        $today = Carbon::today();
        if ($this->starts_at->gt($today) || $this->ends_at->lt($today)) {
            return false;
        }

        return $this->remaining_quantity === null || $this->remaining_quantity > 0;
    }

    /**
     * Effective status for display (document/phase/07 §Offer Performance):
     * archived → scheduled → expired → sold_out → active.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === OfferStatus::Archived) {
            return 'archived';
        }
        $today = Carbon::today();
        if ($this->starts_at->gt($today)) {
            return 'scheduled';
        }
        if ($this->ends_at->lt($today)) {
            return 'expired';
        }
        if ($this->remaining_quantity !== null && $this->remaining_quantity <= 0) {
            return 'sold_out';
        }

        return 'active';
    }

    /** Offers that count against the free-plan "active offers" limit. */
    public function scopeCountsAsActive(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Active->value)
            ->whereDate('ends_at', '>=', Carbon::today());
    }

    /** Offers eligible to appear on the spinner. */
    public function scopeLive(Builder $query): Builder
    {
        $today = Carbon::today();

        return $query->where('status', OfferStatus::Active->value)
            ->where('visibility', 'public')
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->where(function (Builder $q): void {
                $q->whereNull('remaining_quantity')->orWhere('remaining_quantity', '>', 0);
            });
    }
}
