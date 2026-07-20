<?php

namespace Database\Factories;

use App\Models\BusinessCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessCategory>
 */
class BusinessCategoryFactory extends Factory
{
    protected $model = BusinessCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'icon' => 'store',
            'sort_order' => 0,
            'status' => 'active',
        ];
    }
}
