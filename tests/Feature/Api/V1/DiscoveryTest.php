<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Offer;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_public_listing_returns_active_businesses_with_meta(): void
    {
        Business::factory()->count(3)->create();

        $this->getJson('/api/v1/businesses')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'slug', 'name', 'rating', 'offerCount', 'isFavorite']],
                'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
            ]);
    }

    public function test_listing_can_search_by_name(): void
    {
        Business::factory()->create(['name' => 'Chai Point']);
        Business::factory()->create(['name' => 'Pizza Place']);

        $this->getJson('/api/v1/businesses?search=chai')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chai Point');
    }

    public function test_listing_can_filter_by_category(): void
    {
        $cafe = BusinessCategory::factory()->create(['slug' => 'cafe']);
        Business::factory()->create(['category_id' => $cafe->id]);
        Business::factory()->create(); // random category

        $this->getJson('/api/v1/businesses?category=cafe')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_offer_count_reflects_live_offers(): void
    {
        $business = Business::factory()->create();
        Offer::factory()->count(2)->create(['business_id' => $business->id]);

        $this->getJson('/api/v1/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.offerCount', 2);
    }

    public function test_customer_can_favorite_and_unfavorite_a_business(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson('/api/v1/favorites', ['businessSlug' => $business->slug])
            ->assertOk()->assertJsonPath('data.isFavorite', true);

        $this->assertDatabaseHas('favorite_businesses', [
            'customer_id' => $customer->id,
            'business_id' => $business->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/favorites')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->deleteJson("/api/v1/favorites/{$business->slug}")
            ->assertOk()->assertJsonPath('data.isFavorite', false);
    }

    public function test_favorite_flag_is_true_for_the_authenticated_customer(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $this->withToken($this->token($customer))->postJson('/api/v1/favorites', ['businessSlug' => $business->slug]);

        $this->withToken($this->token($customer))->getJson('/api/v1/businesses')
            ->assertJsonPath('data.0.isFavorite', true);
    }

    public function test_customer_can_leave_and_replace_a_review(): void
    {
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $token = $this->token($customer);

        $this->withToken($token)->postJson('/api/v1/reviews', [
            'businessSlug' => $business->slug, 'rating' => 5, 'comment' => 'Great!',
        ])->assertStatus(201)->assertJsonPath('data.rating', 5);

        // Second review from same customer updates, not duplicates.
        $this->withToken($token)->postJson('/api/v1/reviews', [
            'businessSlug' => $business->slug, 'rating' => 3, 'comment' => 'Changed my mind',
        ])->assertStatus(201);

        $this->assertSame(1, $business->reviews()->count());
        $this->assertEquals(3.0, (float) $business->fresh()->average_rating);
        $this->assertSame(1, $business->fresh()->total_reviews);
    }

    public function test_public_can_read_business_reviews(): void
    {
        $business = Business::factory()->create();
        Review::factory()->count(2)->create(['business_id' => $business->id]);

        $this->getJson("/api/v1/businesses/{$business->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'rating', 'customerName', 'isMine']]]);
    }

    public function test_favorites_require_authentication(): void
    {
        $this->getJson('/api/v1/favorites')->assertStatus(401);
    }
}
