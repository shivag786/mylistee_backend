<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'number' => 'INV-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'business_id' => Business::factory(),
            'plan_name' => 'Pro',
            'amount' => 1499,
            'currency' => 'INR',
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
            'paid_at' => now(),
        ];
    }
}
