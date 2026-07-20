<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

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

    private function offerPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Free Coffee',
            'type' => 'free_item',
            'rewardValue' => '1 Free Coffee',
            'startsAt' => Carbon::today()->toDateString(),
            'endsAt' => Carbon::today()->addDays(2)->toDateString(),
        ], $overrides);
    }

    public function test_public_plans_endpoint_lists_seeded_plans(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.key', 'free');
    }

    public function test_owner_defaults_to_the_free_plan(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.usage.activeOffers.limit', 3);
    }

    public function test_owner_can_upgrade_to_a_paid_plan_and_gets_an_invoice(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.usage.activeOffers.limit', null); // pro = unlimited

        $this->assertDatabaseHas('subscriptions', [
            'business_id' => $business->id,
            'status' => 'active',
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 1499)
            ->assertJsonPath('data.0.status', 'paid');
    }

    public function test_upgrading_lifts_the_offer_limit(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        // Fill the free plan's 3 active-offer allowance.
        \App\Models\Offer::factory()->count(3)->create(['business_id' => $business->id]);

        // 4th offer blocked on free.
        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->offerPayload())
            ->assertStatus(422);

        // Upgrade to Pro (unlimited offers) → the 4th now succeeds.
        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertOk();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->offerPayload())
            ->assertStatus(201);
    }

    public function test_pro_plan_allows_longer_offer_validity(): void
    {
        [$owner] = $this->ownerWithBusiness();

        // 10-day offer exceeds free's 3-day cap.
        $long = $this->offerPayload(['endsAt' => Carbon::today()->addDays(10)->toDateString()]);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $long)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endsAt']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertOk();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $long)
            ->assertStatus(201);
    }

    public function test_owner_can_cancel_and_retains_access_until_period_end(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertOk();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'pro') // still Pro until the period ends
            ->assertJsonPath('data.subscription.autoRenew', false)
            ->assertJsonPath('data.subscription.status', 'active');
    }

    public function test_downgrading_to_free_ends_the_paid_subscription(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertOk();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'free'])
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.subscription', null);

        $this->assertDatabaseHas('subscriptions', [
            'business_id' => $business->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_subscribe_rejects_an_unknown_plan(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/subscription', ['planKey' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['planKey']);
    }

    public function test_customer_cannot_access_subscription_endpoints(): void
    {
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/business/subscription')
            ->assertStatus(403);
    }
}
