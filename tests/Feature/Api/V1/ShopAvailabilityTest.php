<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner Settings: close the shop for a while, reopen, pause orders. */
class ShopAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function order(User $customer, Business $business, Product $product)
    {
        return $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ]);
    }

    public function test_a_closed_shop_shows_until_when_takes_no_orders_and_reopens_by_itself(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);

        $this->token($owner)->postJson('/api/v1/business/close', ['hours' => 24, 'note' => 'Family function'])
            ->assertOk()
            ->assertJsonPath('data.isOpen', false)
            ->assertJsonPath('data.closedNote', 'Family function');

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertJsonPath('data.business.isOpen', false)
            ->assertJsonPath('data.business.closedNote', 'Family function');
        $this->assertNotNull($this->getJson("/api/v1/businesses/{$business->slug}")->json('data.business.closedUntil'));

        $this->order($customer, $business, $product)->assertStatus(422)->assertJsonValidationErrors('business');

        // Reopens on its own once the time passes.
        $this->travel(25)->hours();
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertJsonPath('data.business.closedUntil', null);
        $this->order($customer, $business, $product)->assertCreated();
    }

    public function test_reopen_early_and_pause_orders(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);

        $this->token($owner)->postJson('/api/v1/business/close', ['hours' => 720])->assertOk();
        $this->token($owner)->postJson('/api/v1/business/reopen')->assertOk()->assertJsonPath('data.closedUntil', null);

        $this->token($owner)->putJson('/api/v1/business/orders-paused', ['paused' => true])->assertJsonPath('data.ordersPaused', true);
        $this->order($customer, $business, $product)->assertStatus(422);

        $this->token($owner)->putJson('/api/v1/business/orders-paused', ['paused' => false]);
        $this->order($customer, $business, $product)->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'order_placed']);
    }
}
