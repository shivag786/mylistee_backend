<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business} */
    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Free Coffee',
            'type' => 'free_item',
            'rewardValue' => '1 Free Coffee',
            'startsAt' => Carbon::today()->toDateString(),
            'endsAt' => Carbon::today()->addDays(2)->toDateString(),
        ], $overrides);
    }

    public function test_owner_can_create_an_offer(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Free Coffee')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.type', 'free_item');
    }

    public function test_limited_offer_sets_remaining_quantity(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->validPayload(['totalQuantity' => 50]))
            ->assertStatus(201)
            ->assertJsonPath('data.remainingQuantity', 50);
    }

    public function test_free_plan_caps_active_offers_at_three(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Offer::factory()->count(OfferService::FREE_MAX_ACTIVE)->create(['business_id' => $business->id]);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_archived_offers_do_not_count_against_the_limit(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Offer::factory()->count(OfferService::FREE_MAX_ACTIVE)->archived()->create(['business_id' => $business->id]);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->validPayload())
            ->assertStatus(201);
    }

    public function test_offer_validity_cannot_exceed_free_plan_days(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/offers', $this->validPayload([
                'endsAt' => Carbon::today()->addDays(10)->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endsAt']);
    }

    public function test_owner_can_update_archive_and_delete_their_offer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $offer = Offer::factory()->create(['business_id' => $business->id]);
        $token = $this->token($owner);

        $this->withToken($token)
            ->putJson("/api/v1/business/offers/{$offer->uuid}", ['title' => 'Updated'])
            ->assertOk()->assertJsonPath('data.title', 'Updated');

        $this->withToken($token)
            ->patchJson("/api/v1/business/offers/{$offer->uuid}/status", ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->withToken($token)
            ->deleteJson("/api/v1/business/offers/{$offer->uuid}")
            ->assertOk();
        $this->assertSoftDeleted('offers', ['id' => $offer->id]);
    }

    public function test_owner_cannot_touch_another_businesss_offer(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $otherOffer = Offer::factory()->create();

        $this->withToken($this->token($owner))
            ->putJson("/api/v1/business/offers/{$otherOffer->uuid}", ['title' => 'Hacked'])
            ->assertStatus(404);
    }

    public function test_customer_cannot_access_offer_endpoints(): void
    {
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/business/offers')
            ->assertStatus(403);
    }

    public function test_index_lists_only_the_owners_offers(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        Offer::factory()->count(2)->create(['business_id' => $business->id]);
        Offer::factory()->count(3)->create(); // other businesses

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/offers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
