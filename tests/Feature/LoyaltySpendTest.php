<?php

namespace Tests\Feature;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltySpendTest extends TestCase
{
    use RefreshDatabase;

    private function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    /** Give a customer a coin balance at a business. */
    private function fund(User $user, Business $business, int $coins): void
    {
        $this->loyalty()->award($user, CoinSource::Spin, $business, ['amount' => $coins]);
    }

    public function test_redeeming_a_tier_debits_coins_and_mints_a_reward(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $this->fund($customer, $business, 200);
        $tier = LoyaltyReward::factory()->create(['business_id' => $business->id, 'coins_cost' => 150]);

        $reward = $this->loyalty()->redeemTier($customer, $tier);

        $this->assertSame(50, $this->loyalty()->balanceFor($customer));
        $this->assertNotEmpty($reward->code);
        $this->assertDatabaseHas('rewards', [
            'id' => $reward->id,
            'customer_id' => $customer->id,
            'business_id' => $business->id,
            'type' => 'loyalty',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'source' => 'tier_redeem',
            'amount' => -150,
        ]);
    }

    public function test_redeeming_without_enough_coins_fails(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $this->fund($customer, $business, 100);
        $tier = LoyaltyReward::factory()->create(['business_id' => $business->id, 'coins_cost' => 150]);

        $this->expectException(ValidationException::class);
        $this->loyalty()->redeemTier($customer, $tier);
    }

    public function test_redeeming_a_sold_out_tier_fails(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $this->fund($customer, $business, 500);
        $tier = LoyaltyReward::factory()->soldOut()->create(['business_id' => $business->id, 'coins_cost' => 100]);

        $this->expectException(ValidationException::class);
        $this->loyalty()->redeemTier($customer, $tier);
    }

    public function test_stock_decrements_on_redeem(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $this->fund($customer, $business, 500);
        $tier = LoyaltyReward::factory()->create(['business_id' => $business->id, 'coins_cost' => 100, 'stock' => 3]);

        $this->loyalty()->redeemTier($customer, $tier);

        $this->assertSame(2, $tier->fresh()->stock);
    }

    public function test_coins_endpoint_returns_balance_and_breakdown(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('api')->plainTextToken;
        $shopA = Business::factory()->create();
        $shopB = Business::factory()->create();
        $this->fund($customer, $shopA, 120);
        $this->fund($customer, $shopB, 30);

        $this->withToken($token)->getJson('/api/v1/wallet/coins')
            ->assertOk()
            ->assertJsonPath('data.total', 150)
            ->assertJsonCount(2, 'data.businesses')
            ->assertJsonPath('data.businesses.0.balance', 120); // sorted desc
    }

    public function test_customer_can_redeem_a_tier_over_http(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('api')->plainTextToken;
        $business = Business::factory()->create();
        $this->fund($customer, $business, 300);
        $tier = LoyaltyReward::factory()->create(['business_id' => $business->id, 'coins_cost' => 150]);

        $this->withToken($token)->postJson('/api/v1/loyalty/redeem', ['rewardId' => $tier->uuid])
            ->assertStatus(201)
            ->assertJsonPath('data.coinBalance', 150)
            ->assertJsonStructure(['data' => ['reward' => ['id', 'code', 'title', 'status']]]);
    }

    public function test_business_loyalty_endpoint_lists_active_tiers_and_balance(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('api')->plainTextToken;
        $business = Business::factory()->create();
        $this->fund($customer, $business, 90);
        LoyaltyReward::factory()->create(['business_id' => $business->id, 'coins_cost' => 100]);
        LoyaltyReward::factory()->create(['business_id' => $business->id, 'active' => false]); // hidden

        $this->withToken($token)->getJson("/api/v1/businesses/{$business->slug}/loyalty")
            ->assertOk()
            ->assertJsonCount(1, 'data.rewards')
            ->assertJsonPath('data.businessBalance', 90)
            ->assertJsonPath('data.coinBalance', 90);
    }
}
