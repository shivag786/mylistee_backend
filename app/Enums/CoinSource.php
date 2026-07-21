<?php

namespace App\Enums;

/**
 * What triggered a Listee Coins ledger entry (Phase 2). Used both to look up the
 * earn rate (config/loyalty.php `earn.*`) and to explain the entry to the user.
 */
enum CoinSource: string
{
    case Spin = 'spin';
    case FirstScan = 'first_scan';
    case Checkin = 'checkin';
    case Review = 'review';
    case Redeem = 'redeem';
    case Welcome = 'welcome';
    case TierRedeem = 'tier_redeem';
    case AdminAdjust = 'admin_adjust';

    /** The config key under `loyalty.earn` this source earns from, if any. */
    public function earnKey(): ?string
    {
        return match ($this) {
            self::Spin => 'spin',
            self::FirstScan => 'first_scan',
            self::Checkin => 'checkin',
            self::Review => 'review',
            self::Redeem => 'redeem',
            self::Welcome => 'welcome',
            default => null,
        };
    }

    /** The loyalty_programs column that overrides this source's earn rate, if any. */
    public function programColumn(): ?string
    {
        return match ($this) {
            self::Spin => 'coins_per_spin',
            self::FirstScan => 'coins_per_first_scan',
            self::Checkin => 'coins_per_checkin',
            self::Review => 'coins_per_review',
            self::Redeem => 'coins_per_redeem',
            default => null,
        };
    }

    /** Short, friendly label for the wallet transaction list. */
    public function label(): string
    {
        return match ($this) {
            self::Spin => 'Spin reward',
            self::FirstScan => 'First visit bonus',
            self::Checkin => 'Daily check-in',
            self::Review => 'Review reward',
            self::Redeem => 'Redemption bonus',
            self::Welcome => 'Welcome bonus',
            self::TierRedeem => 'Redeemed a reward',
            self::AdminAdjust => 'Adjustment',
        };
    }
}
