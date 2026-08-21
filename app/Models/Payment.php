<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A Razorpay payment attempt for a business plan. Written by
 * {@see \App\Services\SubscriptionPaymentService} — nothing else should mutate it,
 * because the money state here decides whether a plan goes live.
 */
class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'plan_id',
        'invoice_id',
        'subscription_id',
        'created_by',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_signature',
        'receipt',
        'status',
        'amount',
        'amount_paise',
        'currency',
        'method',
        'refunded_amount',
        'error_code',
        'error_description',
        'meta',
        'paid_at',
        'failed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'amount_paise' => 'integer',
            'refunded_amount' => 'decimal:2',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The owner who started the checkout — credited on the subscription.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /** How much of a captured payment is still refundable. */
    public function refundableAmount(): float
    {
        return max(0.0, (float) $this->amount - (float) $this->refunded_amount);
    }

    /** @param  Builder<Payment>  $query */
    public function scopeCaptured(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Captured->value);
    }
}
