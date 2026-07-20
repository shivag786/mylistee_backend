<?php

namespace App\Enums;

/**
 * State of a reward a customer won on the spinner (document/phase/02 §Wallet,
 * phase/10 §wallets). Lives in the customer's wallet until redeemed or expired.
 */
enum RewardStatus: string
{
    case Active = 'active';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
}
