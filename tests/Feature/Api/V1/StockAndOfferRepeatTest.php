<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\ComboItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Stock counts across products and combos, and repeating a past offer. */
class StockAndOfferRepeatTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    public function test_orders_take_stock_alone_and_inside_combos_and_cancel_puts_it_back(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        $cake = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100, 'stock_qty' => 5]);
        $combo = Combo::factory()->create(['business_id' => $business->id, 'combo_price' => 150]);
        ComboItem::create(['combo_id' => $combo->id, 'product_id' => $cake->id, 'quantity' => 2]);

        // 1 cake + 1 combo (2 cakes) = 3 taken.
        $orderId = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [
                ['type' => 'product', 'id' => $cake->uuid, 'quantity' => 1],
                ['type' => 'combo', 'id' => $combo->uuid, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data.id');
        $this->assertSame(2, $cake->fresh()->stock_qty);

        // Asking for more than is left is refused, with how many there are.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $cake->uuid, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonFragment(['Only 2 left of '.$cake->name.'.']);

        // Selling the last two sells it out.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $cake->uuid, 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame(0, $cake->fresh()->stock_qty);
        $this->assertFalse($cake->fresh()->in_stock);

        // Cancelling the first order returns its 3 and puts the cake back on sale.
        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(3, $cake->fresh()->stock_qty);
        $this->assertTrue($cake->fresh()->in_stock);
    }

    public function test_owner_sets_stock_and_cannot_mark_a_zero_count_in_stock(): void
    {
        [$owner, $business] = $this->shop();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock_qty' => 0, 'in_stock' => false]);

        $this->token($owner)->patchJson("/api/v1/business/products/{$product->uuid}/toggle", ['field' => 'in_stock', 'value' => true])
            ->assertUnprocessable();

        $this->token($owner)->post("/api/v1/business/products/{$product->uuid}", [
            '_method' => 'PUT', 'name' => $product->name, 'selling_price' => 50, 'stock_qty' => 12,
        ])->assertOk()->assertJsonPath('data.stockQty', 12)->assertJsonPath('data.inStock', true);
    }

    public function test_an_ended_offer_reads_expired_and_repeats_for_n_days(): void
    {
        [$owner, $business] = $this->shop();
        $product = Product::factory()->create(['business_id' => $business->id]);
        $old = Promotion::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'promotion_type' => PromotionType::Percentage,
            'name' => 'Diwali 20%',
            'config' => ['discount_type' => 'percentage', 'value' => 20],
            // Still "running" in the table: the minute ticker never ran.
            'status' => PromotionStatus::Running,
            'starts_at' => Carbon::now()->subDays(5),
            'ends_at' => Carbon::now()->subDay(),
        ]);

        $this->token($owner)->getJson('/api/v1/business/promotions')
            ->assertOk()->assertJsonPath('data.0.status', 'expired');

        $res = $this->token($owner)->postJson("/api/v1/business/promotions/{$old->uuid}/repeat", ['days' => 3])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Diwali 20%')
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.value', 20)
            ->assertJsonPath('data.productId', $product->uuid);

        $endsAt = Carbon::parse($res->json('data.endsAt'));
        $this->assertTrue($endsAt->isSameDay(Carbon::now()->addDays(2)));
    }
}
