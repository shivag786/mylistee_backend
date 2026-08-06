<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Most ordered" social proof on the public menu, and the post-order review
 * flags (businessSlug + reviewed) that drive the review nudge.
 */
class OrderPopularAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Business
    {
        $owner = User::factory()->businessOwner()->create();

        return Business::factory()->create(['owner_id' => $owner->id]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_a_product_becomes_popular_once_it_passes_the_threshold(): void
    {
        config()->set('orders.popular.threshold', 5);
        $business = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        // 5 units in one order clears the threshold.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 5]],
        ])->assertCreated();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.menu.0.products.0.orderCount', 5)
            ->assertJsonPath('data.business.menu.0.products.0.isPopular', true);
    }

    public function test_a_barely_ordered_product_is_not_popular(): void
    {
        config()->set('orders.popular.threshold', 5);
        $business = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 2]],
        ])->assertCreated();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.menu.0.products.0.orderCount', 2)
            ->assertJsonPath('data.business.menu.0.products.0.isPopular', false);
    }

    public function test_order_exposes_review_state_and_flips_after_reviewing(): void
    {
        $business = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $orderUuid = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        // Owner fulfils the order so the customer can leave a verified review.
        $owner = $business->owner;
        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderUuid}/status", ['status' => 'confirmed'])->assertOk();
        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderUuid}/status", ['status' => 'paid', 'payment_method' => 'cod'])->assertOk();

        // Not reviewed yet — the nudge would show.
        $this->token($customer)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.businessSlug', $business->slug)
            ->assertJsonPath('data.0.reviewed', false);

        // Customer reviews the shop via the fulfilled order.
        $this->token($customer)->postJson('/api/v1/reviews', [
            'orderId' => $orderUuid,
            'rating' => 5,
            'comment' => 'Great!',
        ])->assertSuccessful();

        // Now reviewed — the nudge is suppressed.
        $this->token($customer)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.reviewed', true);
    }
}
