<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $mrp = fake()->numberBetween(100, 500);

        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'name' => ucfirst(fake()->words(2, true)),
            'mrp' => $mrp,
            'selling_price' => $mrp - fake()->numberBetween(0, 50),
            'food_type' => fake()->randomElement(['veg', 'non_veg', null]),
            'in_stock' => true,
            'is_visible' => true,
            'position' => 0,
        ];
    }
}
