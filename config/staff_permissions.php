<?php

/**
 * What a shop's staff may do in the vendor app (EnforceStaffPermissions).
 *
 * The owner can do everything. A staff member can reach an owner API route
 * only when it is in `always` or in a permission they were given; anything
 * not listed here (plan & billing, shop profile, settings…) stays owner-only.
 * Route names are matched with Str::is, so `business.products.*` works.
 *
 * `roles` are starting sets of ticks the owner can change per person.
 */
return [
    // Every signed-in staff member: what the app needs to open, read the menu
    // and tables, and take their own attendance.
    'always' => [
        'business.profile.show',
        'business.dashboard',
        'business.subscription.show',
        'business.product-categories.index',
        'business.products.index',
        'business.combos.index',
        'business.tables.index',
        'business.service-settings.show',
        'business.billing.show',
        'business.attendance.punch',
        'business.attendance.me',
        'business.order-alerts.test',
    ],

    'permissions' => [
        'take_orders' => [
            'name' => 'Take & edit orders',
            'description' => 'Counter orders, add items, change table, accept customer orders',
            'routes' => ['business.orders.index', 'business.orders.store', 'business.orders.quote', 'business.orders.update', 'business.orders.status', 'business.walk-ins', 'business.token.lookup', 'business.kitchen.index'],
        ],
        'settle_bills' => [
            'name' => 'Collect payment',
            'description' => 'Mark bills paid (cash / UPI / card) and complete orders',
            'routes' => ['business.orders.index', 'business.orders.status'],
        ],
        'cancel_orders' => [
            'name' => 'Cancel orders & remove items',
            'description' => 'Cancel an order or take items off one already sent to the kitchen (owner is alerted)',
            'routes' => ['business.orders.index', 'business.orders.status', 'business.orders.update'],
        ],
        'discount' => [
            'name' => 'Give discounts',
            'description' => 'Discount on a bill, up to the limit you set (owner is alerted above the alert limit)',
            'routes' => ['business.orders.update', 'business.orders.store', 'business.orders.quote'],
        ],
        'kitchen' => [
            'name' => 'Kitchen screen',
            'description' => 'See orders to make and mark them preparing / ready',
            'routes' => ['business.kitchen.index', 'business.orders.kitchen', 'business.orders.index'],
        ],
        'menu' => [
            'name' => 'Edit menu',
            'description' => 'Add and change dishes, prices, stock and combos',
            'routes' => ['business.products.*', 'business.product-categories.*', 'business.combos.*'],
        ],
        'cash' => [
            'name' => 'Cash drawer & day close',
            'description' => 'Cash in / out of the drawer and the end-of-day cash count',
            'routes' => ['business.day-report', 'business.day-close.*', 'business.cash-movements.*'],
        ],
        'reports' => [
            'name' => 'See sales & reports',
            'description' => 'Day-end report, analytics and sales numbers',
            'routes' => ['business.day-report', 'business.day-close.index', 'business.analytics'],
        ],
        'staff' => [
            'name' => 'Manage staff & attendance',
            'description' => 'Run the attendance tablet and see / correct attendance',
            'routes' => ['business.attendance.*', 'business.staff.index'],
        ],
        'marketing' => [
            'name' => 'Customers & marketing',
            'description' => 'Customers, offers, coupons, loyalty, reviews and notifications',
            'routes' => [
                'business.customers.*', 'business.followers.*', 'business.reviews.*', 'business.promotions.*',
                'business.rewards.*', 'business.coupons.*', 'business.loyalty.*', 'business.offers.*',
                'business.push.*', 'business.whatsapp.*', 'business.reels.*', 'business.redeem', 'business.redeem.*',
                'business.redemptions', 'business.ai.*', 'business.qr.*',
            ],
        ],
        'alerts' => [
            'name' => 'Get owner alerts',
            'description' => 'Also receive the alerts sent to the owner (cancellations, cash difference…)',
            'routes' => ['business.alerts.index', 'business.alerts.read'],
        ],
    ],

    'roles' => [
        'manager' => ['name' => 'Manager', 'permissions' => ['take_orders', 'settle_bills', 'cancel_orders', 'discount', 'kitchen', 'menu', 'cash', 'reports', 'staff', 'marketing', 'alerts'], 'maxDiscount' => null],
        'cashier' => ['name' => 'Cashier', 'permissions' => ['take_orders', 'settle_bills', 'discount', 'cash'], 'maxDiscount' => 10],
        'waiter' => ['name' => 'Waiter / Captain', 'permissions' => ['take_orders'], 'maxDiscount' => 0],
        'kitchen' => ['name' => 'Kitchen / Chef', 'permissions' => ['kitchen'], 'maxDiscount' => 0],
        'other' => ['name' => 'Other (helper, cleaner…)', 'permissions' => [], 'maxDiscount' => 0],
    ],
];
