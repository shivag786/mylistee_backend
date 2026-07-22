<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Combo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Combo>
 */
class ComboFactory extends Factory
{
    protected $model = Combo::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'name' => ucfirst(fake()->words(2, true)).' combo',
            'combo_price' => fake()->numberBetween(100, 400),
            'wallet_coins_accepted' => false,
            'auto_enable' => true,
            'auto_disable' => true,
            'is_visible' => true,
            'position' => 0,
        ];
    }
}
