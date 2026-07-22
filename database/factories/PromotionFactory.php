<?php

namespace Database\Factories;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'business_id' => Business::factory(),
            'promotion_type' => 'percentage',
            'name' => ucfirst(fake()->words(2, true)).' offer',
            'config' => ['discount_type' => 'percentage', 'value' => 20],
            'status' => PromotionStatus::Running,
            'auto_start' => true,
            'auto_stop' => true,
            'priority' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => PromotionStatus::Running]);
    }
}
