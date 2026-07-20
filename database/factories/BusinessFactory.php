<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'owner_id' => User::factory()->businessOwner(),
            'category_id' => BusinessCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'owner_name' => fake()->name(),
            'description' => fake()->sentence(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'opening_time' => '09:00:00',
            'closing_time' => '21:00:00',
            'phone' => fake()->numerify('##########'),
            'email' => fake()->companyEmail(),
            'status' => BusinessStatus::Active,
            'verified' => false,
            'featured' => false,
        ];
    }
}
