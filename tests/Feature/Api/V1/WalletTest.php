<?php

namespace Tests\Feature\Api\V1;

use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_wallet_summary_counts_rewards_by_status(): void
    {
        $customer = User::factory()->create();
        Reward::factory()->count(2)->create(['customer_id' => $customer->id]);
        Reward::factory()->redeemed()->create(['customer_id' => $customer->id]);

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.summary.available', 2)
            ->assertJsonPath('data.summary.redeemed', 1)
            ->assertJsonPath('data.summary.total', 3);
    }

    public function test_reading_the_wallet_lazily_expires_stale_rewards(): void
    {
        $customer = User::factory()->create();
        Reward::factory()->expired()->create(['customer_id' => $customer->id]);

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.summary.expired', 1)
            ->assertJsonPath('data.summary.available', 0);
    }

    public function test_rewards_can_be_filtered_by_status(): void
    {
        $customer = User::factory()->create();
        Reward::factory()->count(2)->create(['customer_id' => $customer->id]);
        Reward::factory()->redeemed()->create(['customer_id' => $customer->id]);

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/wallet/rewards?status=active')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'code', 'title', 'status', 'business']]]);
    }

    public function test_wallet_only_shows_the_customers_own_rewards(): void
    {
        $customer = User::factory()->create();
        Reward::factory()->create(['customer_id' => $customer->id]);
        Reward::factory()->count(3)->create(); // other customers

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/wallet/rewards')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_wallet_requires_authentication(): void
    {
        $this->getJson('/api/v1/wallet')->assertStatus(401);
    }
}
