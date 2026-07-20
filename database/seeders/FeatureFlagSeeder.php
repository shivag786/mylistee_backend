<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

/**
 * Default feature flags (document/phase/14 §Feature Flags). `firstOrCreate` so
 * admin toggles survive a re-seed. Live features default on; roadmap features off.
 */
class FeatureFlagSeeder extends Seeder
{
    /** @var list<array{key: string, name: string, description: string, enabled: bool}> */
    private const FLAGS = [
        ['key' => 'spinner', 'name' => 'Spinner', 'description' => 'The reward spinner wheel', 'enabled' => true],
        ['key' => 'wallet', 'name' => 'Wallet', 'description' => 'Customer reward wallet', 'enabled' => true],
        ['key' => 'reviews', 'name' => 'Reviews', 'description' => 'Customer reviews & ratings', 'enabled' => true],
        ['key' => 'favorites', 'name' => 'Favorites', 'description' => 'Bookmark businesses', 'enabled' => true],
        ['key' => 'push_notifications', 'name' => 'Push notifications', 'description' => 'Web push via FCM', 'enabled' => true],
        ['key' => 'referral_program', 'name' => 'Referral program', 'description' => 'Refer-a-friend rewards (roadmap)', 'enabled' => false],
        ['key' => 'scratch_cards', 'name' => 'Scratch cards', 'description' => 'Scratch-card rewards (roadmap)', 'enabled' => false],
        ['key' => 'loyalty', 'name' => 'Loyalty points', 'description' => 'Points-based loyalty (roadmap)', 'enabled' => false],
        ['key' => 'ai_recommendations', 'name' => 'AI recommendations', 'description' => 'Personalized suggestions (roadmap)', 'enabled' => false],
    ];

    public function run(): void
    {
        foreach (self::FLAGS as $flag) {
            FeatureFlag::firstOrCreate(['key' => $flag['key']], $flag);
        }
    }
}
