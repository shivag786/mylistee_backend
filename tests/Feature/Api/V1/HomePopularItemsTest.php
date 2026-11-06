<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Most ordered near you" on the customer home. */
class HomePopularItemsTest extends TestCase
{
    use RefreshDatabase;

    private function ordered(Product $p, int $qty, OrderStatus $status = OrderStatus::Completed): void
    {
        $order = Order::factory()->create(['business_id' => $p->business_id, 'status' => $status]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $p->id,
            'item_type' => 'product',
            'name' => $p->name,
            'unit_price' => 100,
            'quantity' => $qty,
        ]);
    }

    public function test_lists_most_ordered_dishes_first_and_caps_each_shop(): void
    {
        $busy = Business::factory()->create();
        $a = Product::factory()->create(['business_id' => $busy->id, 'name' => 'Momos']);
        $b = Product::factory()->create(['business_id' => $busy->id]);
        $c = Product::factory()->create(['business_id' => $busy->id]);
        $other = Business::factory()->create();
        $d = Product::factory()->create(['business_id' => $other->id, 'name' => 'Chaat']);

        $this->ordered($a, 9);
        $this->ordered($b, 5);
        $this->ordered($c, 4);
        $this->ordered($d, 7);
        $this->ordered($d, 50, OrderStatus::Cancelled);

        $items = collect($this->getJson('/api/v1/home/popular-items')->assertOk()->json('data'));

        $this->assertSame(['Momos', 'Chaat'], $items->take(2)->pluck('name')->all());
        $this->assertSame(7, $items[1]['orderCount']);
        $this->assertSame(2, $items->where('business.slug', $busy->slug)->count());
        $this->assertSame($busy->slug, $items[0]['business']['slug']);
    }

    public function test_hidden_or_sold_out_dishes_are_left_out(): void
    {
        $shop = Business::factory()->create();
        $hidden = Product::factory()->create(['business_id' => $shop->id, 'is_visible' => false]);
        $soldOut = Product::factory()->create(['business_id' => $shop->id, 'in_stock' => false]);
        $this->ordered($hidden, 10);
        $this->ordered($soldOut, 10);

        $this->getJson('/api/v1/home/popular-items')->assertOk()->assertJsonCount(0, 'data');
    }
}
