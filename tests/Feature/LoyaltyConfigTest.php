<?php

namespace Tests\Feature;

use App\Enums\CoinSource;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business, 2: string} */
    private function owner(): array
    {
        $business = Business::factory()->create();
        $owner = $business->owner;
        $token = $owner->createToken('api')->plainTextToken;

        return [$owner, $business, $token];
    }

    public function test_owner_sees_effective_program_defaults(): void
    {
        config()->set('loyalty.earn.spin', 10);
        [, , $token] = $this->owner();

        $this->withToken($token)->getJson('/api/v1/business/loyalty')
            ->assertOk()
            ->assertJsonPath('data.program.enabled', true)
            ->assertJsonPath('data.program.coinsPerSpin', 10)
            ->assertJsonPath('data.rewards', []);
    }

    public function test_owner_can_override_earn_rates(): void
    {
        config()->set('loyalty.earn.spin', 10);
        [, $business, $token] = $this->owner();

        $this->withToken($token)->putJson('/api/v1/business/loyalty', [
            'enabled' => true,
            'coinsPerSpin' => 40,
        ])->assertOk()->assertJsonPath('data.coinsPerSpin', 40);

        // The override actually changes what a spin awards.
        $this->assertSame(40, app(LoyaltyService::class)->earnRate(CoinSource::Spin, $business->fresh()));
        $this->assertDatabaseHas('loyalty_programs', ['business_id' => $business->id, 'coins_per_spin' => 40]);
    }

    public function test_disabling_the_program_stops_awards(): void
    {
        config()->set('loyalty.earn.spin', 10);
        [, $business, $token] = $this->owner();

        $this->withToken($token)->putJson('/api/v1/business/loyalty', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $customer = User::factory()->create();
        $this->assertNull(app(LoyaltyService::class)->award($customer, CoinSource::Spin, $business->fresh()));
    }

    public function test_owner_can_manage_reward_tiers(): void
    {
        [, $business, $token] = $this->owner();

        $create = $this->withToken($token)->postJson('/api/v1/business/loyalty/rewards', [
            'title' => 'Free Coffee',
            'coinsCost' => 150,
            'rewardValue' => '1 Free Coffee',
        ])->assertStatus(201)->assertJsonPath('data.coinsCost', 150);

        $uuid = $create->json('data.id');
        $this->assertDatabaseHas('loyalty_rewards', ['business_id' => $business->id, 'title' => 'Free Coffee']);

        $this->withToken($token)->putJson("/api/v1/business/loyalty/rewards/{$uuid}", [
            'title' => 'Free Latte',
            'coinsCost' => 200,
        ])->assertOk()->assertJsonPath('data.title', 'Free Latte');

        $this->withToken($token)->deleteJson("/api/v1/business/loyalty/rewards/{$uuid}")->assertOk();
        $this->assertSoftDeleted('loyalty_rewards', ['uuid' => $uuid]);
    }

    public function test_owner_cannot_touch_another_businesss_reward(): void
    {
        [, , $token] = $this->owner();
        $otherReward = LoyaltyReward::factory()->create(); // belongs to a different business

        $this->withToken($token)
            ->putJson("/api/v1/business/loyalty/rewards/{$otherReward->uuid}", ['title' => 'Hijack', 'coinsCost' => 1])
            ->assertStatus(404);
    }

    public function test_customer_cannot_access_owner_loyalty_config(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $token = $customer->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/business/loyalty')->assertStatus(403);
    }
}
