<?php

/**
 * Toggleable business-owner menu modules. An admin turns these on/off globally
 * (Owner menu page → `owner_<id>` feature flags). When off, the module is hidden
 * from every owner's navigation and its route is blocked.
 *
 * Core modules (Dashboard, Business profile, Plan & billing) are intentionally
 * NOT listed here — they can never be disabled, so an owner is never locked out
 * of their own account or billing.
 *
 * The frontend owner nav references the same ids (see frontend ownerNav.ts).
 */
return [
    'orders' => ['name' => 'Orders', 'description' => 'Incoming order queue + status actions'],
    'products' => ['name' => 'Products / Menu', 'description' => 'Manage products and the menu'],
    'tables' => ['name' => 'Tables & service', 'description' => 'Dine-in tables, pickup and delivery settings'],
    'combos' => ['name' => 'Combos', 'description' => 'Combo builder'],
    'grow_sales' => ['name' => 'Grow sales', 'description' => 'Promotions and discounts'],
    'loyalty' => ['name' => 'Loyalty / Coins', 'description' => 'Loyalty program and coin rewards'],
    'redeem' => ['name' => 'Redeem', 'description' => 'Redeem reward PINs at the counter'],
    'spin_rewards' => ['name' => 'Spin rewards', 'description' => 'Spinner offers'],
    'analytics' => ['name' => 'Analytics', 'description' => 'Business insights and stats'],
    'reviews' => ['name' => 'Reviews', 'description' => 'Customer reviews and replies'],
    'qr' => ['name' => 'QR code', 'description' => 'Business QR code'],
];
