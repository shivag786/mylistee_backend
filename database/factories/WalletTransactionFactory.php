<?php

namespace Database\Factories;

use App\Enums\CoinSource;
use App\Enums\CoinTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(5, 50);

        return [
            'user_id' => User::factory(),
            'business_id' => null,
            'type' => CoinTransactionType::Earn,
            'source' => CoinSource::Spin,
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'Spin reward',
        ];
    }

    public function spent(int $amount = 100): static
    {
        return $this->state(fn () => [
            'type' => CoinTransactionType::Spend,
            'source' => CoinSource::TierRedeem,
            'amount' => -abs($amount),
            'balance_after' => 0,
            'description' => 'Redeemed a reward',
        ]);
    }
}
