<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's loyalty configuration (Phase 2). Null earn-rate columns fall back
 * to config/loyalty.php — see LoyaltyService for the resolution.
 */
class LoyaltyProgram extends Model
{
    protected $fillable = [
        'business_id',
        'enabled',
        'coins_per_spin',
        'coins_per_first_scan',
        'coins_per_checkin',
        'coins_per_review',
        'coins_per_redeem',
        'monthly_budget_cap',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'coins_per_spin' => 'integer',
            'coins_per_first_scan' => 'integer',
            'coins_per_checkin' => 'integer',
            'coins_per_review' => 'integer',
            'coins_per_redeem' => 'integer',
            'monthly_budget_cap' => 'integer',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
