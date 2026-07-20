<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business} */
    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_owner_can_verify_a_valid_reward_code(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $reward = Reward::factory()->create(['business_id' => $business->id, 'code' => 'ABCD1234']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/redeem/verify', ['code' => 'abcd1234'])
            ->assertOk()
            ->assertJsonPath('data.code', 'ABCD1234')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.customerName', $reward->customer->name);
    }

    public function test_owner_can_redeem_a_reward(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $reward = Reward::factory()->create(['business_id' => $business->id, 'code' => 'REDEEM99']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/redeem', ['code' => 'REDEEM99'])
            ->assertOk()
            ->assertJsonPath('data.status', 'redeemed');

        $this->assertDatabaseHas('rewards', [
            'id' => $reward->id,
            'status' => 'redeemed',
            'redeemed_by' => $owner->id,
        ]);
    }

    public function test_a_reward_cannot_be_redeemed_twice(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Reward::factory()->redeemed()->create(['business_id' => $business->id, 'code' => 'USEDONCE']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/redeem', ['code' => 'USEDONCE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_expired_reward_cannot_be_redeemed(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Reward::factory()->expired()->create(['business_id' => $business->id, 'code' => 'EXPIRED1']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/redeem', ['code' => 'EXPIRED1'])
            ->assertStatus(422);
    }

    public function test_owner_cannot_redeem_a_code_from_another_business(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $otherReward = Reward::factory()->create(['code' => 'OTHERBIZ']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/redeem', ['code' => 'OTHERBIZ'])
            ->assertStatus(422);
        $this->assertDatabaseHas('rewards', ['id' => $otherReward->id, 'status' => 'active']);
    }

    public function test_redemption_history_lists_redeemed_rewards(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Reward::factory()->redeemed()->count(2)->create(['business_id' => $business->id]);
        Reward::factory()->create(['business_id' => $business->id]); // active, excluded

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/redemptions')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_customer_cannot_access_redemption_endpoints(): void
    {
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->postJson('/api/v1/business/redeem', ['code' => 'ANYTHING1'])
            ->assertStatus(403);
    }
}
