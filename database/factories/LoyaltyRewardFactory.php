<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\LoyaltyReward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyReward>
 */
class LoyaltyRewardFactory extends Factory
{
    protected $model = LoyaltyReward::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'title' => fake()->randomElement(['Free Coffee', 'Free Dessert', '20% Off', 'Free Upgrade']),
            'description' => fake()->optional()->sentence(),
            'coins_cost' => fake()->randomElement([100, 150, 250, 500]),
            'reward_value' => fake()->randomElement(['1 Free Coffee', '20% off', 'Free item']),
            'active' => true,
            'stock' => null,
            'sort_order' => 0,
        ];
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }
}
