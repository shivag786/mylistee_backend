<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's POS settings: GST billing, the business day, owner alert rules and
 * attendance rules. One row per shop, made on first read with the defaults.
 */
class BusinessPosSetting extends Model
{
    protected $fillable = [
        'business_id',
        'gst_enabled',
        'legal_name',
        'fssai',
        'prices_include_tax',
        'default_tax_rate',
        'service_charge_percent',
        'round_off',
        'invoice_prefix',
        'bill_footer',
        'day_start_hour',
        'alert_settings',
        'attendance_settings',
    ];

    protected $attributes = [
        'gst_enabled' => false,
        'prices_include_tax' => false,
        'default_tax_rate' => 5,
        'service_charge_percent' => 0,
        'round_off' => true,
        'day_start_hour' => 4,
    ];

    protected function casts(): array
    {
        return [
            'gst_enabled' => 'boolean',
            'prices_include_tax' => 'boolean',
            'default_tax_rate' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'round_off' => 'boolean',
            'day_start_hour' => 'integer',
            'alert_settings' => 'array',
            'attendance_settings' => 'array',
        ];
    }

    /** What the owner is alerted about, and the limits that trigger it. */
    public const ALERT_DEFAULTS = [
        'cancel' => true,             // staff cancelled an order
        'itemRemoved' => true,        // staff took an item off an order already in the kitchen
        'discount' => true,           // staff gave a discount at or above discountPercent
        'discountPercent' => 10,
        'cashDifference' => true,     // day closed with cash short/over by more than cashDifferenceAmount
        'cashDifferenceAmount' => 100,
        'orderWaiting' => true,       // a customer's order not accepted within orderWaitingMinutes
        'orderWaitingMinutes' => 5,
        'lowStock' => true,           // a counted product ran out
        'dailySummary' => true,       // the day's sales at dailySummaryTime
        'dailySummaryTime' => '23:30',
        'attendance' => true,         // staff not checked in after shift start + grace
        'staffLogin' => false,        // every staff sign-in
    ];

    /** How attendance is taken. */
    public const ATTENDANCE_DEFAULTS = [
        'selfPunch' => true,     // staff punch on their own phone (selfie + GPS)
        'kiosk' => true,         // staff punch on the shop's device with their PIN
        'requirePhoto' => true,
        'radiusMeters' => 150,   // own-phone punches must be this close to the shop (0 = anywhere)
        'graceMinutes' => 10,    // late after shift start + this
        'halfDayHours' => 4,     // fewer worked hours than this = half day
    ];

    /** @return array<string, mixed> */
    public function alerts(): array
    {
        return array_merge(self::ALERT_DEFAULTS, array_intersect_key((array) $this->alert_settings, self::ALERT_DEFAULTS));
    }

    /** @return array<string, mixed> */
    public function attendance(): array
    {
        return array_merge(self::ATTENDANCE_DEFAULTS, array_intersect_key((array) $this->attendance_settings, self::ATTENDANCE_DEFAULTS));
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
