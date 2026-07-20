<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Default subscription plans (document/phase/02 §Subscriptions / §Free Plan /
 * §Premium Plan, phase/14 §Subscription Management). These are *defaults* — the
 * Super Admin can edit every limit and price (Milestone 14), so this uses
 * firstOrCreate and never clobbers admin changes on re-seed. Prices are ₹ INR
 * (the product's launch market — GST field, Indian context). A null limit means
 * "unlimited".
 */
class PlanSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const PLANS = [
        [
            'key' => 'free',
            'name' => 'Free',
            'description' => 'Everything you need to get started.',
            'price' => 0,
            'interval' => 'month',
            'max_active_offers' => 3,
            'max_offer_days' => 3,
            'max_qr_codes' => 1,
            'max_gallery_images' => 6,
            'features' => ['basic_analytics', 'reviews'],
            'badge' => null,
            'is_default' => true,
            'sort_order' => 0,
        ],
        [
            'key' => 'starter',
            'name' => 'Starter',
            'description' => 'For growing local shops.',
            'price' => 499,
            'interval' => 'month',
            'max_active_offers' => 10,
            'max_offer_days' => 7,
            'max_qr_codes' => 1,
            'max_gallery_images' => 15,
            'features' => ['analytics', 'reviews', 'email_support'],
            'badge' => null,
            'is_default' => false,
            'sort_order' => 1,
        ],
        [
            'key' => 'pro',
            'name' => 'Pro',
            'description' => 'Unlock the full growth toolkit.',
            'price' => 1499,
            'interval' => 'month',
            'max_active_offers' => null, // unlimited
            'max_offer_days' => 30,
            'max_qr_codes' => 3,
            'max_gallery_images' => 50,
            'features' => [
                'analytics', 'advanced_analytics', 'reviews', 'push_notifications',
                'scheduled_campaigns', 'customer_segmentation', 'loyalty', 'priority_support',
            ],
            'badge' => 'Popular',
            'is_default' => false,
            'sort_order' => 2,
        ],
        [
            'key' => 'enterprise',
            'name' => 'Enterprise',
            'description' => 'For chains, franchises and multi-branch brands.',
            'price' => 4999,
            'interval' => 'month',
            'max_active_offers' => null,
            'max_offer_days' => null,
            'max_qr_codes' => null,
            'max_gallery_images' => null,
            'features' => [
                'analytics', 'advanced_analytics', 'reviews', 'push_notifications',
                'scheduled_campaigns', 'customer_segmentation', 'loyalty', 'multi_branch',
                'white_label', 'api_access', 'dedicated_support',
            ],
            'badge' => null,
            'is_default' => false,
            'sort_order' => 3,
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            Plan::firstOrCreate(
                ['key' => $plan['key']],
                array_merge($plan, ['currency' => 'INR', 'is_public' => true]),
            );
        }
    }
}
