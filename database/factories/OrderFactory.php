<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(100, 500);

        return [
            'uuid' => (string) Str::uuid(),
            'token' => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'business_id' => Business::factory(),
            'customer_id' => User::factory(),
            'status' => OrderStatus::Placed,
            'subtotal' => $subtotal,
            'coins_used' => 0,
            'coin_discount' => 0,
            'total' => $subtotal,
            'coins_earned' => 0,
            'placed_at' => now(),
        ];
    }
}
