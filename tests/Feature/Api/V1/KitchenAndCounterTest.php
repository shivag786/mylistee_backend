<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vendor app kitchen flow: walk-in orders at the counter, accepted orders on
 * the kitchen screen with a running "to make" total, ready → customer told,
 * and the shop type deciding whether there is a kitchen at all.
 */
class KitchenAndCounterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business} */
    private function shop(string $type = 'food'): array
    {
        $owner = User::factory()->businessOwner()->create();
        $category = BusinessCategory::factory()->create(['business_type' => $type]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'category_id' => $category->id]);

        return [$owner, $business];
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** A product sold in Half / Full. */
    private function momos(Business $business, string $name): Product
    {
        return Product::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
            'selling_price' => 100,
            'options' => ['variants' => [
                ['id' => 'h', 'name' => 'Half', 'price' => 60],
                ['id' => 'f', 'name' => 'Full', 'price' => 100],
            ]],
        ]);
    }

    public function test_a_walk_in_order_starts_accepted_in_the_kitchen(): void
    {
        [$owner, $business] = $this->shop();
        $momos = $this->momos($business, 'Veg Steam Momos');

        $this->as($owner)->postJson('/api/v1/business/orders', [
            'items' => [['type' => 'product', 'id' => $momos->uuid, 'variant' => 'f', 'quantity' => 1]],
            'customerName' => 'Rahul',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.source', 'counter')
            ->assertJsonPath('data.kitchenStatus', 'queued')
            ->assertJsonPath('data.customerName', 'Rahul')
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.items.0.name', 'Veg Steam Momos (Full)');

        $this->assertDatabaseHas('orders', ['business_id' => $business->id, 'customer_id' => null, 'source' => 'counter']);
    }

    public function test_a_walk_in_order_can_be_paid_at_once(): void
    {
        [$owner, $business] = $this->shop();
        $momos = $this->momos($business, 'Veg Momos');

        $this->as($owner)->postJson('/api/v1/business/orders', [
            'items' => [['type' => 'product', 'id' => $momos->uuid, 'variant' => 'h']],
            'paid' => 'online',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paymentMethod', 'online')
            // Paid at the counter, but still to be made.
            ->assertJsonPath('data.kitchenStatus', 'queued');
    }

    public function test_the_kitchen_adds_up_what_to_make_across_orders(): void
    {
        [$owner, $business] = $this->shop();
        $steam = $this->momos($business, 'Veg Steam Momos');
        $paneer = $this->momos($business, 'Paneer Momos');
        $fry = $this->momos($business, 'Veg Fry Momos');
        $kurkure = $this->momos($business, 'Kurkure Veg Momos');
        $noodles = $this->momos($business, 'Noodles');
        $platter = Product::factory()->create(['business_id' => $business->id, 'name' => 'Platter', 'selling_price' => 250]);

        $order = fn (array $items) => $this->as($owner)->postJson('/api/v1/business/orders', ['items' => $items])
            ->assertCreated()->json('data.token');

        $line = fn (Product $p, ?string $size = null) => ['type' => 'product', 'id' => $p->uuid, 'variant' => $size];
        $t1 = $order([$line($steam, 'f')]);
        $t2 = $order([$line($paneer, 'h'), $line($fry, 'h')]);
        $order([$line($kurkure, 'f')]);
        $t4 = $order([$line($noodles, 'h'), $line($steam, 'f'), $line($platter)]);

        $kitchen = $this->as($owner)->getJson('/api/v1/business/kitchen')->assertOk()->json('data');

        $this->assertTrue($kitchen['kitchenEnabled']);
        $this->assertCount(4, $kitchen['orders']);
        $this->assertSame($t1, $kitchen['orders'][0]['token']); // oldest first

        $toMake = collect($kitchen['toMake'])->keyBy('name');
        $this->assertSame(2, $toMake['Veg Steam Momos (Full)']['quantity']);
        $this->assertEqualsCanonicalizing([$t1, $t4], $toMake['Veg Steam Momos (Full)']['tokens']);
        $this->assertSame(1, $toMake['Paneer Momos (Half)']['quantity']);
        $this->assertSame([$t2], $toMake['Veg Fry Momos (Half)']['tokens']);
        $this->assertSame(1, $toMake['Platter']['quantity']);
        $this->assertCount(6, $kitchen['toMake']);
    }

    public function test_ready_tells_the_customer_and_leaves_the_queue(): void
    {
        [$owner, $business] = $this->shop();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $momos = $this->momos($business, 'Veg Momos');

        $id = $this->as($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $momos->uuid, 'variant' => 'f']],
        ])->assertCreated()->json('data.id');

        // Placed orders wait for the shop; accepting sends them to the kitchen.
        $this->as($owner)->getJson('/api/v1/business/kitchen')->assertJsonCount(0, 'data.orders');
        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}/status", ['status' => 'confirmed'])
            ->assertJsonPath('data.kitchenStatus', 'queued');

        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}/kitchen", ['status' => 'preparing'])
            ->assertOk()->assertJsonPath('data.kitchenStatus', 'preparing');
        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}/kitchen", ['status' => 'ready'])
            ->assertOk()->assertJsonPath('data.kitchenStatus', 'ready');

        $kitchen = $this->as($owner)->getJson('/api/v1/business/kitchen')->json('data');
        $this->assertCount(0, $kitchen['orders']);
        $this->assertSame($id, $kitchen['ready'][0]['id']);
        $this->assertContains('Your order is ready 🍽️', $customer->appNotifications()->pluck('title')->all());

        // Handed over: gone from the kitchen entirely.
        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}/status", ['status' => 'paid'])->assertOk();
        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}/status", ['status' => 'completed'])->assertOk();
        $this->as($owner)->getJson('/api/v1/business/kitchen')->assertJsonCount(0, 'data.ready');
    }

    public function test_only_accepted_orders_can_move_in_the_kitchen(): void
    {
        [$owner, $business] = $this->shop();
        $order = Order::factory()->create(['business_id' => $business->id, 'status' => 'placed']);

        $this->as($owner)->patchJson("/api/v1/business/orders/{$order->uuid}/kitchen", ['status' => 'ready'])
            ->assertStatus(422);
    }

    public function test_a_retail_shop_has_no_kitchen_until_the_owner_turns_it_on(): void
    {
        [$owner, $business] = $this->shop('retail');
        $soap = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 40]);

        $this->as($owner)->getJson('/api/v1/business/profile')
            ->assertJsonPath('data.businessType', 'retail')
            ->assertJsonPath('data.kitchenEnabled', false);
        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => [['type' => 'product', 'id' => $soap->uuid]]])
            ->assertCreated()->assertJsonPath('data.kitchenStatus', null);

        $this->as($owner)->putJson('/api/v1/business/kitchen', ['enabled' => true])
            ->assertOk()->assertJsonPath('data.kitchenEnabled', true);
        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => [['type' => 'product', 'id' => $soap->uuid]]])
            ->assertCreated()->assertJsonPath('data.kitchenStatus', 'queued');

        // Back to what the shop type suggests.
        $this->as($owner)->putJson('/api/v1/business/kitchen', ['enabled' => null])
            ->assertOk()->assertJsonPath('data.kitchenEnabled', false);
    }

    public function test_the_admin_sets_a_categorys_shop_type(): void
    {
        $admin = User::factory()->admin()->create();
        $category = BusinessCategory::factory()->create(['business_type' => 'food']);

        $this->as($admin)->postJson("/api/v1/admin/categories/{$category->uuid}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'business_type' => 'service',
        ])->assertOk()->assertJsonPath('data.businessType', 'service');

        $this->as($admin)->postJson("/api/v1/admin/categories/{$category->uuid}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'business_type' => 'spaceship',
        ])->assertStatus(422);
    }
}
