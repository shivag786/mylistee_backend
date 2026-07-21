<?php

namespace App\Enums;

/**
 * Direction of a Listee Coins ledger entry (Phase 2). The signed `amount` on the
 * row is authoritative; this classifies why the balance moved.
 */
enum CoinTransactionType: string
{
    case Earn = 'earn';     // customer gained coins (positive amount)
    case Spend = 'spend';   // customer spent coins on a reward tier (negative)
    case Adjust = 'adjust'; // manual admin correction (either sign)
    case Expire = 'expire'; // coins expired (negative)
}
