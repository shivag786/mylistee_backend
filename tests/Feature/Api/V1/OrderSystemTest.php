<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CoinSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSystemTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function token(User $user): static
    {
        // Re-resolve the guard so switching users mid-test picks up the new token.
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_customer_places_an_order_with_snapshot_prices_and_a_token(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        $combo = Combo::factory()->create(['business_id' => $business->id, 'combo_price' => 150, 'coins_earned' => 15]);

        $res = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [
                ['type' => 'product', 'id' => $product->uuid, 'quantity' => 1],
                ['type' => 'combo', 'id' => $combo->uuid, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.subtotal', 250)
            ->assertJsonPath('data.total', 250)
            ->assertJsonPath('data.coinsEarned', 15)
            ->assertJsonCount(2, 'data.items');

        $this->assertMatchesRegularExpression('/^\d{4}$/', $res->json('data.token'));
        // The owner is notified of the new order.
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'order_placed']);
    }

    public function test_wallet_coins_are_spent_at_checkout_and_capped(): void
    {
        [, $business] = $this->shop();
        $customer = $this->customer();
        app(LoyaltyService::class)->award($customer, CoinSource::Welcome, $business, ['amount' => 100]);
        Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        $product = Product::first();

        // Ask to use 9999 coins on a ₹100 order — capped to 100 balance then to subtotal.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'coinsToUse' => 9999,
        ])->assertCreated()
            ->assertJsonPath('data.coinsUsed', 100)
            ->assertJsonPath('data.coinDiscount', 100)
            ->assertJsonPath('data.total', 0);

        // Balance is now 100 - 100 = 0.
        $this->assertSame(0, app(LoyaltyService::class)->balanceForBusiness($customer, $business));
    }

    public function test_owner_confirms_and_marks_paid_crediting_coins(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        $combo = Combo::factory()->create(['business_id' => $business->id, 'combo_price' => 150, 'coins_earned' => 20]);

        $orderId = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'combo', 'id' => $combo->uuid, 'quantity' => 1]],
        ])->json('data.id');

        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'paid'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Combo coins are credited on payment.
        $this->assertSame(20, app(LoyaltyService::class)->balanceForBusiness($customer, $business));
        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'type' => 'order_update']);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [$owner, $business] = $this->shop();
        $order = Order::factory()->create(['business_id' => $business->id, 'status' => OrderStatus::Placed]);

        // placed → paid is not allowed (must confirm first).
        $this->token($owner)->patchJson("/api/v1/business/orders/{$order->uuid}/status", ['status' => 'paid'])
            ->assertStatus(422);
    }

    public function test_cancelling_refunds_spent_coins(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        app(LoyaltyService::class)->award($customer, CoinSource::Welcome, $business, ['amount' => 50]);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $orderId = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'coinsToUse' => 40,
        ])->json('data.id');

        $this->assertSame(10, app(LoyaltyService::class)->balanceForBusiness($customer, $business));

        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // 40 coins returned → back to 50.
        $this->assertSame(50, app(LoyaltyService::class)->balanceForBusiness($customer, $business));
    }

    public function test_orders_are_scoped_to_the_owning_business(): void
    {
        [, $businessA] = $this->shop();
        [$ownerB] = $this->shop();
        $order = Order::factory()->create(['business_id' => $businessA->id]);

        $this->token($ownerB)->patchJson("/api/v1/business/orders/{$order->uuid}/status", ['status' => 'confirmed'])
            ->assertStatus(404);
    }

    public function test_customer_sees_only_their_orders(): void
    {
        [, $business] = $this->shop();
        $customer = $this->customer();
        Order::factory()->create(['business_id' => $business->id, 'customer_id' => $customer->id]);
        Order::factory()->create(['business_id' => $business->id]); // someone else's

        $this->token($customer)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
