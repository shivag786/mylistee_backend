<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's service configuration (hasOne). `modes` lists the enabled
 * ServiceType values; absent row ⇒ pickup-only. See Business::serviceModes().
 */
class BusinessServiceSetting extends Model
{
    /** Take the whole total online. */
    public const PAYMENT_FULL = 'full';

    /** Take `partial_percent` of the total online, the rest at the counter. */
    public const PAYMENT_PARTIAL = 'partial';

    /** Lowest up-front share a shop can ask for, so "partial" means something. */
    public const PARTIAL_MIN = 10;

    /** Highest -- at 100 it is just "full" by another name. */
    public const PARTIAL_MAX = 90;

    protected $fillable = [
        'business_id',
        'modes',
        'default_mode',
        'delivery_fee',
        'payment_mode',
        'partial_percent',
        'cod_enabled',
    ];

    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'delivery_fee' => 'decimal:2',
            'partial_percent' => 'integer',
            'cod_enabled' => 'boolean',
        ];
    }

    public function isPartial(): bool
    {
        return $this->payment_mode === self::PAYMENT_PARTIAL;
    }

    /**
     * How much of an order's total is taken online.
     *
     * Rounded to the rupee, up: the up-front share is a deposit, and a deposit of
     * 59.40 is a number nobody wants to see on a UPI screen. Rounding up rather
     * than to nearest keeps it from ever falling under the percentage the shop
     * asked for. Never more than the total itself.
     */
    public function onlinePortion(float $total): float
    {
        if (! $this->isPartial()) {
            return round($total, 2);
        }

        $percent = max(self::PARTIAL_MIN, min(self::PARTIAL_MAX, (int) $this->partial_percent));

        return min(round($total, 2), (float) ceil($total * $percent / 100));
    }

    /** Enabled modes as ServiceType enums, always including Pickup as a floor. */
    public function enabledTypes(): array
    {
        $types = [];
        foreach ((array) $this->modes as $value) {
            $type = ServiceType::tryFrom((string) $value);
            if ($type !== null) {
                $types[$type->value] = $type;
            }
        }
        $types[ServiceType::Pickup->value] ??= ServiceType::Pickup;

        return array_values($types);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
