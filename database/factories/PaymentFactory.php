<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->randomElement([499, 1499, 4999]);

        return [
            'business_id' => Business::factory(),
            'plan_id' => Plan::factory(),
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(14),
            'status' => PaymentStatus::Created,
            'amount' => $amount,
            'amount_paise' => $amount * 100,
            'currency' => 'INR',
        ];
    }

    public function captured(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Captured,
            'gateway_payment_id' => 'pay_'.Str::random(14),
            'method' => 'upi',
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'error_code' => 'BAD_REQUEST_ERROR',
            'error_description' => 'Payment failed',
            'failed_at' => now(),
        ]);
    }
}
