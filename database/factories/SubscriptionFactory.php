<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'plan_id' => Plan::factory()->paid(),
            'status' => SubscriptionStatus::Active,
            'price' => 1499,
            'currency' => 'INR',
            'interval' => 'month',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ];
    }
}
