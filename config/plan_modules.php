<?php

/**
 * What a plan can include (admin → Plans). Each module is switched on per plan,
 * optionally for a number of days. Core parts of the panel — dashboard,
 * profile, menu, QR, personal link, settings, billing — are never listed here:
 * a shop always stays visible, whatever its plan.
 *
 *  - `paths`: the owner API sections (first segment after /business/) the
 *    module covers. A locked module's sections refuse changes; reading stays
 *    open so the owner still sees what they had.
 *  - `readLocked`: also refuse reads (pure-insight modules like analytics).
 *  - `customer`: takes effect on the customer side (checkout).
 */
return [
    'ordering' => ['name' => 'Online ordering', 'description' => 'Customers add to cart and order. Off = menu shows as a catalogue.', 'group' => 'Orders & payments', 'paths' => [], 'customer' => true],
    'delivery' => ['name' => 'Home delivery', 'description' => 'Customers can order for delivery', 'group' => 'Orders & payments', 'paths' => [], 'customer' => true],
    'pay_counter' => ['name' => 'Pay at counter', 'description' => 'Cash / pay at the shop', 'group' => 'Orders & payments', 'paths' => [], 'customer' => true],
    'pay_online' => ['name' => 'Pay online', 'description' => 'UPI / card through Razorpay', 'group' => 'Orders & payments', 'paths' => [], 'customer' => true],
    'tables' => ['name' => 'Tables & dine-in', 'description' => 'Dining tables with table QR', 'group' => 'Orders & payments', 'paths' => ['tables']],
    'combos' => ['name' => 'Combos', 'description' => 'Combo deals', 'group' => 'Sell more', 'paths' => ['combos']],
    'grow_sales' => ['name' => 'Grow sales', 'description' => 'Promotions and timed discounts', 'group' => 'Sell more', 'paths' => ['promotions']],
    'rewards' => ['name' => 'Rewards & coupons', 'description' => 'Bill discount, come-back and loyalty-step coupons, coupon codes', 'group' => 'Bring customers back', 'paths' => ['rewards', 'coupons']],
    'loyalty' => ['name' => 'Loyalty coins', 'description' => 'Coins customers earn and spend at the shop', 'group' => 'Bring customers back', 'paths' => ['loyalty']],
    'spin_rewards' => ['name' => 'Spin rewards', 'description' => 'Spin-and-win offers', 'group' => 'Bring customers back', 'paths' => ['offers']],
    'redeem' => ['name' => 'Redeem', 'description' => 'Redeem reward PINs at the counter', 'group' => 'Bring customers back', 'paths' => ['redeem', 'redemptions', 'token']],
    // Checked when sending (ShopPushService), which explains why it is blocked.
    'notifications' => ['name' => 'Notify customers', 'description' => 'Push offers to followers and customers', 'group' => 'Bring customers back', 'paths' => []],
    // Checked when sending (WhatsAppCampaignService); the monthly count is plans.whatsapp_per_month.
    'whatsapp' => ['name' => 'WhatsApp offers', 'description' => 'Send ready-made offer templates on WhatsApp', 'group' => 'Bring customers back', 'paths' => []],
    'customers' => ['name' => 'Customers', 'description' => 'The shop\'s own customer list', 'group' => 'Know your customers', 'paths' => ['customers']],
    'reviews' => ['name' => 'Review replies', 'description' => 'Reply to and hide reviews', 'group' => 'Know your customers', 'paths' => ['reviews']],
    'analytics' => ['name' => 'Analytics', 'description' => 'Sales and visit insights', 'group' => 'Know your customers', 'paths' => ['analytics'], 'readLocked' => true],
    'reels' => ['name' => 'Reels', 'description' => 'Short videos on the customer home page', 'group' => 'Get seen', 'paths' => ['reels']],
    'store_mode' => ['name' => 'Store mode', 'description' => 'Link / QR visitors stay inside this shop', 'group' => 'Get seen', 'paths' => []],
];
