<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Spin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Spin>
 */
class SpinFactory extends Factory
{
    protected $model = Spin::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => User::factory(),
            'business_id' => Business::factory(),
            'ip_address' => fake()->ipv4(),
            'device' => fake()->userAgent(),
        ];
    }
}
