<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/** A new order rings the shop owner's phone (FCM) and lands in their notifications. */
class OrderAlertTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $owner->deviceTokens()->create(['token' => 'owner-phone', 'platform' => 'web']);
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business, Product::factory()->create(['business_id' => $business->id, 'selling_price' => 120])];
    }

    public function test_a_new_order_pushes_a_detailed_alert_to_the_owner(): void
    {
        [$owner, $business, $product] = $this->shop();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active, 'name' => 'Priya']);

        $sent = [];
        $this->mock(FirebaseService::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('sendToTokens')->andReturnUsing(function (array $tokens, string $title, string $body, array $data) use (&$sent) {
                $sent[] = compact('tokens', 'title', 'body', 'data');

                return [];
            });
        });

        $order = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 2]],
        ])->assertCreated()->json('data');

        $this->assertCount(1, $sent);
        $this->assertSame(['owner-phone'], $sent[0]['tokens']);
        $this->assertSame("🛎️ New order {$order['token']}", $sent[0]['title']);
        $this->assertSame('Pickup · ₹240 · 2 items · Priya', $sent[0]['body']);
        $this->assertSame('1', $sent[0]['data']['urgent']);
        $this->assertSame("order-{$order['id']}", $sent[0]['data']['tag']);
        $this->assertSame('/business/orders', $sent[0]['data']['link']);

        // The in-app copy is kept too.
        $this->assertSame(1, $owner->appNotifications()->where('type', 'order_placed')->count());
    }

    public function test_owner_can_send_a_test_alert_once_a_phone_is_registered(): void
    {
        [$owner] = $this->shop();
        $this->mock(FirebaseService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('sendToTokens')->once()->andReturn([]);
        });

        $this->token($owner)->postJson('/api/v1/business/order-alerts/test')->assertOk()->assertJsonPath('data.devices', 1);

        // No phone registered → told to switch alerts on first.
        $owner->deviceTokens()->delete();
        $this->token($owner)->postJson('/api/v1/business/order-alerts/test')->assertStatus(422);
    }
}
