<?php

namespace App\Enums;

/**
 * Reward/offer types a business can create (document/phase/07 §Offer Types,
 * phase/02 §Offer Types). New types can be appended without a schema change.
 */
enum OfferType: string
{
    case Percentage = 'percentage';
    case Flat = 'flat';
    case Bogo = 'bogo';
    case Combo = 'combo';
    case FreeItem = 'free_item';
    case Cashback = 'cashback';
    case WalletReward = 'wallet_reward';
    case Festival = 'festival';
    case Mystery = 'mystery';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage discount',
            self::Flat => 'Flat discount',
            self::Bogo => 'Buy one get one',
            self::Combo => 'Combo offer',
            self::FreeItem => 'Free item',
            self::Cashback => 'Cashback',
            self::WalletReward => 'Wallet reward',
            self::Festival => 'Festival offer',
            self::Mystery => 'Mystery reward',
        };
    }
}
