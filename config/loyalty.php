<?php

/**
 * Listee Coins — loyalty program defaults (Phase 2, flagship). Coins are
 * integers, never money. A business can override these per-business earn rates
 * via its loyalty_programs row (added in a later slice); until then, and as the
 * fallback when a business has no program configured, these platform defaults
 * apply. Never hardcode these amounts in code — read them through config.
 */
return [
    // Master switch for the whole loyalty layer.
    'enabled' => (bool) env('LOYALTY_ENABLED', true),

    // Rupee value of one coin when spent on an order (Phase 7.5). 1 coin = ₹1 by
    // default. Never hardcode — read via config('loyalty.coin_value').
    'coin_value' => (int) env('LOYALTY_COIN_VALUE', 1),

    // Default coins granted per earning event (document/phase/02 §Rewards).
    'earn' => [
        'spin' => 10,        // every wheel spin
        'first_scan' => 25,  // first time a customer opens a shop's profile
        'checkin' => 5,      // returning to a shop they've already scanned (once/day)
        'review' => 20,      // leaving a review (once per business)
        'redeem' => 15,      // completing a reward redemption
        'welcome' => 50,     // one-time, on account creation
    ],

    // A business cannot mint more than this many coins per calendar month
    // (0 = unlimited). Protects owners from surprise liability.
    'monthly_budget_cap' => (int) env('LOYALTY_MONTHLY_CAP', 0),
];
