<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Review extends Model
{
    /** @use HasFactory<\Database\Factories\ReviewFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'customer_id',
        'shop_customer_id',
        'reviewer_name',
        'order_id',
        'rating',
        'comment',
        'reply',
        'replied_at',
        'status',
        'hidden_by_owner_at',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'replied_at' => 'datetime', 'hidden_by_owner_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review): void {
            if (empty($review->uuid)) {
                $review->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** The completed order that verified this review, if any. @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The link reviewer, when the review came from the shop's review link. @return BelongsTo<ShopCustomer, $this> */
    public function shopCustomer(): BelongsTo
    {
        return $this->belongsTo(ShopCustomer::class);
    }

    /** Optional per-item star ratings. @return HasMany<ReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }

    /** Who to show as the author: the account name, else the name typed on the link form. */
    public function authorName(): ?string
    {
        return $this->customer?->name ?? $this->reviewer_name ?? $this->shopCustomer?->name;
    }
}
