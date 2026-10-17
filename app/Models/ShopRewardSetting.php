<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's automatic rewards, set up once: the bill discount, the come-back
 * coupon and the every-Nth-order loyalty coupon. Each is null when off.
 */
class ShopRewardSetting extends Model
{
    protected $fillable = ['business_id', 'bill_discount', 'comeback', 'loyalty'];

    protected function casts(): array
    {
        return [
            'bill_discount' => 'array',
            'comeback' => 'array',
            'loyalty' => 'array',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
