<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ShopCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopCustomer>
 */
class ShopCustomerFactory extends Factory
{
    protected $model = ShopCustomer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'name' => fake()->name(),
            'email' => null,
            'city' => null,
            'source' => 'manual',
        ];
    }
}
