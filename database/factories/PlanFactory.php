<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'uuid' => (string) Str::uuid(),
            'key' => Str::slug($name).'-'.Str::random(4),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
            'price' => 0,
            'currency' => 'INR',
            'interval' => 'month',
            'max_active_offers' => 3,
            'max_offer_days' => 3,
            'max_qr_codes' => 1,
            'max_gallery_images' => 6,
            'features' => ['reviews'],
            'badge' => null,
            'is_public' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => [
            'key' => 'free',
            'name' => 'Free',
            'price' => 0,
            'is_default' => true,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'price' => 1499,
            'max_active_offers' => null, // unlimited
            'max_offer_days' => 30,
            'max_qr_codes' => 3,
            'features' => ['analytics', 'push_notifications', 'loyalty'],
        ]);
    }
}
