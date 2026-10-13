<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Combo;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShopCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reviews through a shop's public review link (mobile number, no account), and
 * the owner hiding/showing reviews on their page.
 */
class ReviewLinkTest extends TestCase
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

    public function test_link_page_lists_the_shops_visible_items(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Masala Dosa']);
        Product::factory()->create(['business_id' => $business->id, 'is_visible' => false]);
        Combo::factory()->create(['business_id' => $business->id, 'name' => 'Lunch combo']);

        $this->getJson("/api/v1/review-link/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', $business->name)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $product->uuid);
    }

    public function test_customer_reviews_with_mobile_and_text_and_lands_in_the_customer_book(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->postJson("/api/v1/review-link/{$business->slug}", [
            'phone' => '98765 43210',
            'name' => 'Asha',
            'comment' => 'Lovely food.',
        ])->assertCreated()
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.customerName', 'Asha')
            ->assertJsonPath('data.isVerified', false);

        $customer = $business->shopCustomers()->first();
        $this->assertSame('9876543210', $customer->phone);
        $this->assertSame('review', $customer->source);
        $this->assertSame(1, $business->reviews()->count());
    }

    public function test_a_review_needs_a_valid_mobile_and_some_content(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->postJson("/api/v1/review-link/{$business->slug}", ['comment' => 'Nice'])->assertStatus(422);
        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '123', 'comment' => 'Nice'])->assertStatus(422);
        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '9876543210'])->assertStatus(422);
    }

    public function test_item_ratings_are_saved_and_other_shops_items_are_ignored(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $mine = Product::factory()->create(['business_id' => $business->id, 'name' => 'Masala Dosa']);
        $theirs = Product::factory()->create();

        $this->postJson("/api/v1/review-link/{$business->slug}", [
            'phone' => '9876543210',
            'items' => [
                ['type' => 'product', 'id' => $mine->uuid, 'rating' => 5],
                ['type' => 'product', 'id' => $theirs->uuid, 'rating' => 1],
            ],
        ])->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Masala Dosa')
            ->assertJsonPath('data.items.0.rating', 5);
    }

    public function test_resubmitting_from_the_same_mobile_edits_the_review(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '9876543210', 'rating' => 2])->assertCreated();
        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '+919876543210', 'rating' => 5])->assertCreated();

        $this->assertSame(1, $business->reviews()->count());
        $this->assertSame(5, $business->reviews()->first()->rating);
        $this->assertEquals(5.0, (float) $business->fresh()->average_rating);
    }

    public function test_a_name_from_the_form_never_overwrites_one_the_owner_saved(): void
    {
        [, $business] = $this->ownerWithBusiness();
        ShopCustomer::factory()->create(['business_id' => $business->id, 'phone' => '9876543210', 'name' => 'Asha Rao']);

        $this->postJson("/api/v1/review-link/{$business->slug}", [
            'phone' => '9876543210', 'name' => 'Someone else', 'rating' => 4,
        ])->assertCreated();

        $this->assertSame('Asha Rao', $business->shopCustomers()->first()->name);
    }

    public function test_honeypot_submissions_are_dropped(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $this->postJson("/api/v1/review-link/{$business->slug}", [
            'phone' => '9876543210', 'rating' => 5, 'website' => 'http://spam.example',
        ])->assertCreated();

        $this->assertSame(0, $business->reviews()->count());
    }

    public function test_owner_sees_the_reviewers_mobile(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '9876543210', 'rating' => 4])->assertCreated();

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.phone', '9876543210')
            ->assertJsonPath('data.0.source', 'link')
            ->assertJsonPath('data.0.isHidden', false);
    }

    public function test_the_public_list_never_shows_the_reviewers_mobile(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $this->postJson("/api/v1/review-link/{$business->slug}", ['phone' => '9876543210', 'rating' => 4])->assertCreated();

        $response = $this->getJson("/api/v1/businesses/{$business->slug}/reviews")->assertOk();

        $this->assertStringNotContainsString('9876543210', $response->getContent());
    }

    public function test_hidden_reviews_leave_the_public_page_but_still_count_in_the_rating(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $low = Review::factory()->create(['business_id' => $business->id, 'rating' => 1]);
        Review::factory()->create(['business_id' => $business->id, 'rating' => 5]);
        $business->recalculateRating();

        $this->withToken($this->token($owner))
            ->patchJson("/api/v1/business/reviews/{$low->uuid}/visibility", ['hidden' => true])
            ->assertOk()
            ->assertJsonPath('data.isHidden', true);

        $this->getJson("/api/v1/businesses/{$business->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $business->recalculateRating();
        $this->assertEquals(3.0, (float) $business->fresh()->average_rating);

        $this->withToken($this->token($owner))
            ->patchJson("/api/v1/business/reviews/{$low->uuid}/visibility", ['hidden' => false])
            ->assertOk();

        $this->getJson("/api/v1/businesses/{$business->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_owner_cannot_hide_another_shops_review(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $review = Review::factory()->create();

        $this->withToken($this->token($owner))
            ->patchJson("/api/v1/business/reviews/{$review->uuid}/visibility", ['hidden' => true])
            ->assertNotFound();

        $this->assertNull($review->fresh()->hidden_by_owner_at);
    }

    public function test_owner_only_sees_their_own_shops_reviews(): void
    {
        // The first business in the table is someone else's: the old lookup
        // (`business()->firstOrFail()`) used to return that one.
        $foreign = Review::factory()->create();
        [$owner, $business] = $this->ownerWithBusiness();
        $mine = Review::factory()->create(['business_id' => $business->id]);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->uuid);

        $this->assertNotSame($foreign->business_id, $business->id);
    }
}
