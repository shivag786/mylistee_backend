<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessVisit>
 */
class BusinessVisitFactory extends Factory
{
    protected $model = BusinessVisit::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'customer_id' => User::factory(),
            'ip_address' => fake()->ipv4(),
            'device' => fake()->userAgent(),
            'source' => 'qr',
        ];
    }

    /** An anonymous (logged-out) visit. */
    public function anonymous(): static
    {
        return $this->state(fn () => ['customer_id' => null]);
    }
}
