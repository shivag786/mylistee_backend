<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Offer;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpinnerTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(); // default role customer
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function businessWithOffers(int $offers = 2): Business
    {
        $business = Business::factory()->create();
        Offer::factory()->count($offers)->create(['business_id' => $business->id]);

        return $business;
    }

    public function test_public_profile_is_visible_logged_out_with_spin_locked(): void
    {
        $business = $this->businessWithOffers();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.name', $business->name)
            ->assertJsonPath('data.spin.available', true)
            ->assertJsonPath('data.spin.requiresLogin', true)
            ->assertJsonPath('data.spin.alreadySpunToday', null)
            ->assertJsonCount(2, 'data.business.offers');
    }

    public function test_public_profile_hides_owner_only_fields(): void
    {
        $business = Business::factory()->create(['gst' => 'GST123', 'email' => 'owner@x.com']);

        $json = $this->getJson("/api/v1/businesses/{$business->slug}")->json('data.business');
        $this->assertArrayNotHasKey('gst', $json);
        $this->assertArrayNotHasKey('email', $json);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/v1/businesses/nope')->assertStatus(404);
    }

    public function test_authenticated_customer_can_spin_and_win_a_reward(): void
    {
        $customer = $this->customer();
        $business = $this->businessWithOffers();

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['reward' => ['id', 'code', 'title', 'status'], 'offer' => ['id', 'title']]]);

        $this->assertDatabaseHas('rewards', ['customer_id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseHas('spins', ['customer_id' => $customer->id, 'business_id' => $business->id]);
        $this->assertSame(1, $business->fresh()->total_spins);
    }

    public function test_spin_is_limited_to_once_per_business_per_day(): void
    {
        $customer = $this->customer();
        $business = $this->businessWithOffers();
        $token = $this->token($customer);

        $this->withToken($token)->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])->assertOk();

        $this->withToken($token)->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['spin']);
    }

    public function test_spin_requires_authentication(): void
    {
        $business = $this->businessWithOffers();

        $this->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])
            ->assertStatus(401);
    }

    public function test_spin_fails_when_business_has_no_live_offers(): void
    {
        $business = Business::factory()->create(); // no offers

        $this->withToken($this->token($this->customer()))
            ->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])
            ->assertStatus(422);
    }

    public function test_limited_offer_stock_decrements_on_win(): void
    {
        $customer = $this->customer();
        $business = Business::factory()->create();
        $offer = Offer::factory()->limited(5)->create(['business_id' => $business->id]);

        $this->withToken($this->token($customer))
            ->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])
            ->assertOk();

        $this->assertSame(4, $offer->fresh()->remaining_quantity);
    }

    public function test_alreadySpunToday_flag_reflects_history(): void
    {
        $customer = $this->customer();
        $business = $this->businessWithOffers();
        $token = $this->token($customer);

        $this->withToken($token)->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])->assertOk();

        $this->withToken($token)->getJson("/api/v1/businesses/{$business->slug}")
            ->assertJsonPath('data.spin.alreadySpunToday', true);
    }
}
