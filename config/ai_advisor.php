<?php

/**
 * AI Sales Manager (offers, combos, discounts) and AI Customer Growth Advisor.
 *
 * The safety rules here are enforced in code (AiAdvisorGuard) on everything the
 * AI suggests — a suggestion that cannot be made safe is dropped, never saved.
 */
return [
    // Orders that count as sales (same as the day report).
    'counted_statuses' => ['paid', 'completed'],

    // Look-back window for the stats the AI sees.
    'window_days' => 30,

    // Lapsed = ordered 30–90 days ago, nothing in the last 30 days.
    'lapsed_from_days' => 90,
    'lapsed_to_days' => 30,

    // How many suggestions to keep from one AI answer.
    'max_suggestions' => 4,

    'rules' => [
        // No discount (percent, flat-as-share-of-price, BOGO free share) above this.
        'max_discount_percent' => 30,
        // A combo must cost at least this share of its items' normal total.
        'min_combo_price_ratio' => 0.80,
        // Offer length the AI may pick.
        'min_validity_days' => 1,
        'max_validity_days' => 30,
        // Products one item offer may cover.
        'max_products_per_offer' => 10,
    ],

    // Nightly run (ai:advisor-nightly): shops with at least this many counted
    // orders in the window, a pause between AI calls so the free tier's
    // requests-per-minute limit is never hit.
    'nightly' => [
        'at' => '02:30',
        'min_orders' => 5,
        'delay_seconds' => (int) env('AI_ADVISOR_DELAY_SECONDS', 8),
    ],

    // "Refresh suggestions" by the owner: per shop, per kind, per day.
    'manual_refresh_per_day' => 3,

    // The AI sales manager bubble (AiCoachService). Its tips cost no AI
    // tokens; these only ration how often it speaks up by itself.
    'coach' => [
        'nudges_per_day' => (int) env('AI_COACH_NUDGES_PER_DAY', 2),
        'nudge_gap_hours' => (int) env('AI_COACH_NUDGE_GAP_HOURS', 4),
        'repeat_after_days' => 3,
        'snooze_hours' => 24,
    ],
];
