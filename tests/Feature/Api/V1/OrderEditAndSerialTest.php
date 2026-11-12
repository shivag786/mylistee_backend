<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * POS order changes (table, quantities, added items), the order's number of
 * the day, today's orders in one list, and the admin hiding "Pay online".
 */
class OrderEditAndSerialTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        return [$owner, $business, $product];
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    /** @return array{0: string, 1: string} two table uuids */
    private function tables(User $owner): array
    {
        $this->as($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup', 'dine_in'],
            'defaultMode' => 'dine_in',
        ])->assertOk();

        return [
            $this->as($owner)->postJson('/api/v1/business/tables', ['label' => 'T1'])->json('data.id'),
            $this->as($owner)->postJson('/api/v1/business/tables', ['label' => 'T2'])->json('data.id'),
        ];
    }

    public function test_orders_are_numbered_through_the_day_and_start_again_tomorrow(): void
    {
        [$owner, $business, $product] = $this->shop();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Kolkata'));
        $item = [['type' => 'product', 'id' => $product->uuid]];

        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => $item])->assertJsonPath('data.serial', 1);
        $this->as($this->customer())->postJson('/api/v1/orders', ['business' => $business->slug, 'items' => $item])
            ->assertJsonPath('data.serial', 2);

        Carbon::setTestNow(Carbon::parse('2026-11-13 09:00:00', 'Asia/Kolkata'));
        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => $item])->assertJsonPath('data.serial', 1);
    }

    public function test_the_shop_moves_an_order_to_another_table_and_the_customer_is_told(): void
    {
        [$owner, $business, $product] = $this->shop();
        [$t1, $t2] = $this->tables($owner);
        $customer = $this->customer();

        $id = $this->as($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid]],
            'serviceType' => 'dine_in',
            'table' => $t1,
        ])->assertJsonPath('data.tableLabel', 'T1')->json('data.id');

        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}", ['table' => $t2])
            ->assertOk()
            ->assertJsonPath('data.tableLabel', 'T2');

        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'title' => 'Order updated']);
    }

    public function test_the_shop_adds_and_removes_items_and_the_bill_follows(): void
    {
        [$owner, $business, $product] = $this->shop();
        $tea = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 20, 'stock_qty' => 10]);

        $order = $this->as($owner)->postJson('/api/v1/business/orders', [
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 2]],
        ])->assertJsonPath('data.total', 200)->json('data');
        $lineId = $order['items'][0]['id'];

        // Add 3 teas, take one main off.
        $this->as($owner)->patchJson("/api/v1/business/orders/{$order['id']}", [
            'quantities' => [$lineId => 1],
            'add' => [['type' => 'product', 'id' => $tea->uuid, 'quantity' => 3]],
        ])->assertOk()
            ->assertJsonPath('data.total', 160)
            ->assertJsonCount(2, 'data.items');
        $this->assertSame(7, $tea->fresh()->stock_qty);

        // Remove the teas: their stock comes back.
        $teaLine = Order::where('uuid', $order['id'])->first()->items()->where('product_id', $tea->id)->value('id');
        $this->as($owner)->patchJson("/api/v1/business/orders/{$order['id']}", ['quantities' => [$teaLine => 0]])
            ->assertOk()
            ->assertJsonPath('data.total', 100)
            ->assertJsonCount(1, 'data.items');
        $this->assertSame(10, $tea->fresh()->stock_qty);

        // The last item can't go: cancel instead.
        $this->as($owner)->patchJson("/api/v1/business/orders/{$order['id']}", ['quantities' => [$lineId => 0]])
            ->assertStatus(422);
    }

    public function test_a_finished_order_cannot_be_changed(): void
    {
        [$owner, , $product] = $this->shop();
        $id = $this->as($owner)->postJson('/api/v1/business/orders', [
            'items' => [['type' => 'product', 'id' => $product->uuid]],
            'paid' => 'cod',
        ])->json('data.id');

        $this->as($owner)->patchJson("/api/v1/business/orders/{$id}", [
            'add' => [['type' => 'product', 'id' => $product->uuid]],
        ])->assertStatus(422);
    }

    public function test_today_lists_orders_of_every_status(): void
    {
        [$owner, , $product] = $this->shop();
        $item = [['type' => 'product', 'id' => $product->uuid]];
        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => $item]);
        $this->as($owner)->postJson('/api/v1/business/orders', ['items' => $item, 'paid' => 'cod']);
        $day = now('Asia/Kolkata')->format('Y-m-d');

        $this->as($owner)->getJson("/api/v1/business/orders?status=all&from={$day}&to={$day}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_hides_pay_online_for_one_shop(): void
    {
        config(['razorpay.key_id' => 'rzp_test_key', 'razorpay.key_secret' => 'secret']);
        [, $business] = $this->shop();
        $admin = User::factory()->admin()->create();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertJsonPath('data.business.service.payment.onlineAvailable', true);

        $this->as($admin)->patchJson("/api/v1/admin/businesses/{$business->uuid}/online-pay")
            ->assertOk()
            ->assertJsonPath('data.onlinePayHidden', true);

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertJsonPath('data.business.service.payment.onlineAvailable', false)
            ->assertJsonPath('data.business.service.payment.codAvailable', true);
    }
}
