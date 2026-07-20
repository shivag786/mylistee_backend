<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessVisit;
use App\Models\Offer;
use App\Models\Reward;
use App\Models\Spin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
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

    public function test_owner_can_load_analytics_with_summary_series_and_top_offers(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        BusinessVisit::factory()->count(4)->create(['business_id' => $business->id]);
        Spin::factory()->count(3)->create(['business_id' => $business->id]);
        $offer = Offer::factory()->create(['business_id' => $business->id]);
        Reward::factory()->count(2)->create(['business_id' => $business->id, 'offer_id' => $offer->id]);
        Reward::factory()->redeemed()->create(['business_id' => $business->id, 'offer_id' => $offer->id]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/analytics?days=30')
            ->assertOk()
            ->assertJsonPath('data.range.days', 30)
            ->assertJsonPath('data.summary.visits.value', 4)
            ->assertJsonPath('data.summary.spins.value', 3)
            ->assertJsonPath('data.summary.rewards.value', 3)
            ->assertJsonPath('data.summary.redemptions.value', 1)
            ->assertJsonCount(30, 'data.series')
            ->assertJsonPath('data.topOffers.0.uuid', $offer->uuid)
            ->assertJsonPath('data.topOffers.0.rewards', 3);
    }

    public function test_analytics_range_defaults_and_clamps_to_allowed_windows(): void
    {
        [$owner] = $this->ownerWithBusiness();

        // Unsupported window falls back to the 30-day default.
        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/analytics?days=999')
            ->assertOk()
            ->assertJsonPath('data.range.days', 30)
            ->assertJsonCount(30, 'data.series');

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/analytics?days=7')
            ->assertOk()
            ->assertJsonPath('data.range.days', 7)
            ->assertJsonCount(7, 'data.series');
    }

    public function test_repeat_customer_rate_is_computed(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $repeat = User::factory()->create();
        $once = User::factory()->create();

        Spin::factory()->count(2)->create(['business_id' => $business->id, 'customer_id' => $repeat->id]);
        Spin::factory()->create(['business_id' => $business->id, 'customer_id' => $once->id]);

        // 1 of 2 customers is a repeat visitor → 50%.
        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/analytics')
            ->assertOk()
            ->assertJsonPath('data.summary.repeatCustomerRate', 50);
    }

    public function test_visiting_a_public_profile_records_a_visit(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk();

        $this->assertDatabaseHas('business_visits', ['business_id' => $business->id]);
        $this->assertSame(1, $business->fresh()->total_visits);
    }

    public function test_repeat_rapid_visits_are_deduplicated(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk();
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk();

        // Same anonymous visitor within the de-dupe window → a single visit.
        $this->assertSame(1, BusinessVisit::where('business_id', $business->id)->count());
    }

    public function test_customer_cannot_access_business_analytics(): void
    {
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/business/analytics')
            ->assertStatus(403);
    }
}
