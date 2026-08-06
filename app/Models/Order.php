<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'business_id',
        'customer_id',
        'table_id',
        'status',
        'service_type',
        'payment_method',
        'subtotal',
        'coins_used',
        'coin_discount',
        'total',
        'delivery_fee',
        'coins_earned',
        'note',
        'service_address',
        'placed_at',
        'confirmed_at',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'service_type' => ServiceType::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'coin_discount' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
}
