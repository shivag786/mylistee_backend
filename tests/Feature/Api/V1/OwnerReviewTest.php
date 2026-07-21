<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerReviewTest extends TestCase
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

    public function test_owner_can_list_and_reply_to_reviews(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $review = Review::factory()->create(['business_id' => $business->id, 'reply' => null]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.id', $review->uuid);

        $this->withToken($this->token($owner))
            ->postJson("/api/v1/business/reviews/{$review->uuid}/reply", ['reply' => 'Thanks for visiting!'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Thanks for visiting!');

        $this->assertNotNull($review->fresh()->replied_at);
    }

    public function test_owner_cannot_reply_to_another_businesss_review(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $review = Review::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->withToken($this->token($owner))
            ->postJson("/api/v1/business/reviews/{$review->uuid}/reply", ['reply' => 'Hi'])
            ->assertStatus(404);
    }
}
