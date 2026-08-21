<?php

/**
 * Razorpay payment gateway (business plan subscriptions).
 *
 * Only the *business plan* money flow runs through Razorpay. Customer orders are
 * still settled at the counter (see App\Enums\PaymentMethod) and Listee Coins are
 * not money — neither touches this gateway.
 *
 * Optional by design, exactly like the Anthropic/Google keys in config/services.php:
 * when the keys are absent the app degrades to the pre-gateway "simulated invoice"
 * upgrade path so local development and the demo seed keep working. In production
 * the keys must be present — SubscriptionController refuses paid upgrades without
 * a live gateway.
 */
return [

    'key_id' => env('RAZORPAY_KEY_ID'),
    'key_secret' => env('RAZORPAY_KEY_SECRET'),

    /*
    | Dashboard → Settings → Webhooks. Used to HMAC-verify the raw webhook body.
    | Without it webhooks are rejected (401) rather than trusted blindly.
    */
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),

    'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),

    /** Seconds to wait on a Razorpay API call before failing the request. */
    'timeout' => (int) env('RAZORPAY_TIMEOUT', 30),

    /*
    | Presentation for the hosted Checkout modal. The frontend never hardcodes
    | these — they ride along in the /subscription/checkout response.
    */
    'checkout' => [
        'name' => env('RAZORPAY_CHECKOUT_NAME', env('APP_NAME', 'Listee')),
        'theme_color' => env('RAZORPAY_THEME_COLOR', '#E23744'),
        'logo' => env('RAZORPAY_CHECKOUT_LOGO'),
    ],

    /*
    | Auto-capture authorized payments. Razorpay voids an uncaptured payment
    | after ~5 days, so leave this on unless you run a manual-capture workflow.
    */
    'auto_capture' => (bool) env('RAZORPAY_AUTO_CAPTURE', true),

];
