<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Followers are favourites read from the shop's side. These cover who is
 * allowed to see the list and what it does or does not expose.
 */
class FollowerTest extends TestCase
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

    public function test_owner_sees_the_customers_who_followed_their_shop(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $customer = User::factory()->create(['name' => 'Asha Rao']);
        Favorite::create(['customer_id' => $customer->id, 'business_id' => $business->id]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/followers')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.followers.0.name', 'Asha Rao');
    }

    public function test_the_list_never_exposes_a_followers_contact_details(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $customer = User::factory()->create(['email' => 'private@example.com']);
        Favorite::create(['customer_id' => $customer->id, 'business_id' => $business->id]);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/followers')
            ->assertOk();

        $this->assertStringNotContainsString('private@example.com', $response->getContent());
    }

    public function test_an_owner_cannot_see_another_shops_followers(): void
    {
        [$owner] = $this->ownerWithBusiness();
        [, $otherBusiness] = $this->ownerWithBusiness();
        $customer = User::factory()->create();
        Favorite::create(['customer_id' => $customer->id, 'business_id' => $otherBusiness->id]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/followers')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_a_customer_cannot_read_the_followers_endpoint(): void
    {
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/business/followers')
            ->assertForbidden();
    }

    public function test_the_public_profile_reports_following_for_a_follower(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $follower = User::factory()->create();
        Favorite::create(['customer_id' => $follower->id, 'business_id' => $business->id]);

        $this->withToken($this->token($follower))
            ->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.isFollowing', true)
            ->assertJsonPath('data.business.followersCount', 1);
    }

    // Separate test rather than a second call in the one above: the auth guard
    // holds on to the user it resolved for the first request, so two signed-in
    // viewers in a single test measure the guard's cache, not the endpoint.
    public function test_the_public_profile_reports_not_following_for_someone_else(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $follower = User::factory()->create();
        $stranger = User::factory()->create();
        Favorite::create(['customer_id' => $follower->id, 'business_id' => $business->id]);

        $this->withToken($this->token($stranger))
            ->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.isFollowing', false)
            ->assertJsonPath('data.business.followersCount', 1);
    }

    public function test_a_signed_out_visitor_sees_the_count_but_is_not_following(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $follower = User::factory()->create();
        Favorite::create(['customer_id' => $follower->id, 'business_id' => $business->id]);

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.isFollowing', false)
            ->assertJsonPath('data.business.followersCount', 1);
    }
}
