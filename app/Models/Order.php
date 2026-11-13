<?php

namespace App\Models;

use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A customer order for one shop (Phase 7.5).
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'token',
        'source',
        'customer_label',
        'kitchen_status',
        'kitchen_started_at',
        'ready_at',
        'business_id',
        'customer_id',
        'table_id',
        'status',
        'service_type',
        'payment_method',
        'payment_choice',
        'online_amount',
        'convenience_fee',
        'platform_fee',
        'gateway_fee',
        'vendor_earning',
        'bill_discount',
        'coupon_id',
        'coupon_discount',
        'online_paid_at',
        'subtotal',
        'coins_used',
        'coin_discount',
        'total',
        'delivery_fee',
        'coins_earned',
        'stock_deducted',
        'note',
        'service_address',
        'delivery_phone',
        'delivery_lat',
        'delivery_lng',
        'placed_at',
        'serial',
        'confirmed_at',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'paid_by',
        // GST billing & staff (POS layer)
        'tax_mode',
        'taxable_amount',
        'cgst',
        'sgst',
        'service_charge',
        'service_charge_percent',
        'round_off',
        'manual_discount',
        'manual_discount_percent',
        'discount_reason',
        'invoice_no',
        'created_by',
        'discount_by',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'service_type' => ServiceType::class,
            'payment_method' => PaymentMethod::class,
            'online_amount' => 'decimal:2',
            'convenience_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'vendor_earning' => 'decimal:2',
            'bill_discount' => 'decimal:2',
            'coupon_discount' => 'decimal:2',
            'manual_discount' => 'decimal:2',
            'manual_discount_percent' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'round_off' => 'decimal:2',
            'online_paid_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'coin_discount' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'delivery_lat' => 'float',
            'delivery_lng' => 'float',
            'stock_deducted' => 'array',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'kitchen_status' => KitchenStatus::class,
            'kitchen_started_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Whether the online share has landed. */
    public function isPaidOnline(): bool
    {
        return $this->online_paid_at !== null;
    }

    /**
     * What the shop still has to collect at the counter.
     *
     * The online share comes off only once it has actually been captured -- an
     * order sitting in AwaitingPayment has taken nothing yet. The convenience
     * fee is never part of this: it paid for the gateway, not for the food.
     */
    public function amountDue(): float
    {
        $paid = $this->isPaidOnline() ? (float) $this->online_amount : 0.0;

        return max(0.0, round((float) $this->total - $paid, 2));
    }

    /**
     * The next number of the day at this shop (1, 2, 3...), counted from the
     * shop's midnight. Call inside the transaction that places the order.
     */
    public static function nextSerialFor(int $businessId): int
    {
        $start = \Illuminate\Support\Carbon::now(config('app.business_timezone', 'Asia/Kolkata'))->startOfDay()->utc();

        return (int) static::where('business_id', $businessId)
            ->where('placed_at', '>=', $start)
            ->whereNotNull('serial')
            ->lockForUpdate()
            ->max('serial') + 1;
    }

    /** Give the order its number of the day, once (it reached the shop). */
    public function assignSerial(): void
    {
        if ($this->serial === null) {
            $this->forceFill(['serial' => static::nextSerialFor($this->business_id)])->save();
        }
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
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

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Every Razorpay attempt for this order's online share. @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The attempt that tells an admin where the money stands. A retried payment
     * leaves failed attempts behind it; the latest one is the one that counts.
     *
     * @return HasOne<Payment, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    /**
     * How the bill was settled, as one word: 'cod', 'online' (all of it) or
     * 'partial' (an advance online, the rest at the counter).
     *
     * Orders from before online payment have no choice recorded; they were all
     * cash, so they read as 'cod'.
     */
    public function paymentType(): string
    {
        if ($this->payment_choice !== 'online' || (float) $this->online_amount <= 0) {
            return 'cod';
        }

        return (float) $this->online_amount < (float) $this->total ? 'partial' : 'online';
    }

    /**
     * The dining table this order is bound to (dine-in only), or null. Named
     * `diningTable` rather than `table` to avoid colliding with Eloquent's
     * reserved `$table` (DB table name) property.
     *
     * @return BelongsTo<BusinessTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(BusinessTable::class, 'table_id');
    }

    /** Short human label for how the order is served, e.g. "Table 5" or "Takeaway". */
    public function serviceLabel(): string
    {
        $type = $this->service_type instanceof ServiceType ? $this->service_type : ServiceType::tryFrom((string) $this->service_type);
        if ($type === ServiceType::DineIn && $this->diningTable) {
            return $this->diningTable->label;
        }

        return ($type ?? ServiceType::default())->label();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Coupon, $this> */
    public function coupon(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Who took it at the counter (owner or staff). @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who marked it paid. @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** Who cancelled it. @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
