<?php

namespace Database\Factories;

use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\Offer;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'business_id' => Business::factory(),
            'offer_id' => Offer::factory(),
            'title' => fake()->words(3, true),
            'reward_value' => fake()->randomElement(['10%', '20%', '1 Free Coffee']),
            'type' => 'free_item',
            'status' => RewardStatus::Active,
            'won_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(3),
        ];
    }

    public function redeemed(): static
    {
        return $this->state(fn () => [
            'status' => RewardStatus::Redeemed,
            'redeemed_at' => Carbon::now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => RewardStatus::Active,
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }
}
