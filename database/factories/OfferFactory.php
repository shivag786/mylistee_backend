<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Business;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(OfferType::cases()),
            'reward_value' => fake()->randomElement(['10%', '20%', '₹50 off', '1 Free Coffee']),
            'starts_at' => Carbon::today(),
            'ends_at' => Carbon::today()->addDays(2),
            'total_quantity' => null,
            'remaining_quantity' => null,
            'weight' => 1,
            'priority' => 0,
            'status' => OfferStatus::Active,
            'premium_only' => false,
            'visibility' => 'public',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => OfferStatus::Archived]);
    }

    public function limited(int $quantity): static
    {
        return $this->state(fn () => [
            'total_quantity' => $quantity,
            'remaining_quantity' => $quantity,
        ]);
    }
}
