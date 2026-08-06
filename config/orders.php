<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "Most ordered" social proof
    |--------------------------------------------------------------------------
    |
    | Powers the "🔥 N ordered" / Popular badge on the customer menu. A product
    | or combo is Popular when it has been ordered at least `threshold` times
    | (quantity, across non-cancelled orders) within the last `window_days`.
    |
    */

    'popular' => [
        'window_days' => (int) env('ORDERS_POPULAR_WINDOW_DAYS', 90),
        'threshold' => (int) env('ORDERS_POPULAR_THRESHOLD', 5),
    ],

];
