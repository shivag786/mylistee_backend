<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sizes, add-ons and the product's own % / ₹ discount, from menu to order. */
class ProductOptionsTest extends TestCase
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

    public function test_owner_saves_sizes_addons_and_a_percent_discount(): void
    {
        [$owner] = $this->shop();

        $res = $this->token($owner)->post('/api/v1/business/products', [
            'name' => 'Pizza',
            'mrp' => 200,
            'selling_price' => 180,
            'variants' => json_encode([['name' => 'Small', 'price' => 200], ['name' => 'Large', 'price' => 400]]),
            'addons' => json_encode([['name' => 'Extra cheese', 'price' => 40]]),
            'discount' => json_encode(['type' => 'percent', 'value' => 10]),
        ])->assertCreated()
            ->assertJsonPath('data.variants.0.name', 'Small')
            ->assertJsonPath('data.variants.1.sellingPrice', 360)
            ->assertJsonPath('data.addons.0.price', 40)
            ->assertJsonPath('data.discount.type', 'percent')
            ->assertJsonPath('data.discountPercent', 10);

        // Ids are minted for new rows and kept when the owner edits.
        $largeId = $res->json('data.variants.1.id');
        $this->assertNotEmpty($largeId);

        $this->token($owner)->post('/api/v1/business/products/'.$res->json('data.id'), [
            '_method' => 'PUT',
            'name' => 'Pizza',
            'selling_price' => 200,
            'variants' => json_encode([['id' => $largeId, 'name' => 'Large', 'price' => 450]]),
            'addons' => '[]',
            'discount' => '',
        ])->assertOk()
            ->assertJsonPath('data.variants.0.id', $largeId)
            ->assertJsonPath('data.variants.0.sellingPrice', 450)
            ->assertJsonCount(0, 'data.addons')
            ->assertJsonPath('data.discount', null);
    }

    public function test_sizes_carry_a_type_and_quantity_through_to_the_order(): void
    {
        [$owner, $business] = $this->shop();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);

        // Veg momo: Steam or Fried, each in Half / Full with a piece count — or a type alone.
        $res = $this->token($owner)->post('/api/v1/business/products', [
            'name' => 'Veg Momo',
            'selling_price' => 50,
            'variants' => json_encode([
                ['type' => 'Steam', 'name' => 'Half', 'qty' => '5 pcs', 'price' => 50],
                ['type' => 'Fried', 'name' => 'Full', 'qty' => '10 pcs', 'price' => 110],
                ['type' => 'Kurkure', 'price' => 90],
            ]),
        ])->assertCreated()
            ->assertJsonPath('data.variants.0.type', 'Steam')
            ->assertJsonPath('data.variants.0.qty', '5 pcs')
            ->assertJsonPath('data.variants.1.label', 'Fried · Full · 10 pcs')
            ->assertJsonPath('data.variants.2.label', 'Kurkure');

        // A row needs a size or a type.
        $this->token($owner)->post('/api/v1/business/products', [
            'name' => 'Momo',
            'selling_price' => 50,
            'variants' => json_encode([['qty' => '5 pcs', 'price' => 50]]),
        ])->assertUnprocessable();

        $product = Product::where('uuid', $res->json('data.id'))->sole();
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'variant' => $res->json('data.variants.1.id')]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.name', 'Veg Momo (Fried · Full · 10 pcs)')
            ->assertJsonPath('data.items.0.unitPrice', 110);
    }

    public function test_a_flat_discount_comes_off_every_size(): void
    {
        $product = Product::factory()->make(['options' => ['discount' => ['type' => 'flat', 'value' => 30]]]);

        $this->assertSame(170.0, $product->applyDiscount(200));
        $this->assertSame(0.0, $product->applyDiscount(20));
    }

    public function test_order_is_priced_by_the_chosen_size_and_addons(): void
    {
        [, $business] = $this->shop();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Pizza',
            'selling_price' => 180,
            'options' => [
                'variants' => [['id' => 'sm', 'name' => 'Small', 'price' => 200], ['id' => 'lg', 'name' => 'Large', 'price' => 400]],
                'addons' => [['id' => 'chz', 'name' => 'Extra cheese', 'price' => 40], ['id' => 'dip', 'name' => 'Dip', 'price' => 20]],
                'discount' => ['type' => 'percent', 'value' => 10],
            ],
        ]);

        // Large 400 − 10% = 360, + cheese 40 = 400 a unit, × 2.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 2, 'variant' => 'lg', 'addons' => ['chz']]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', 800)
            ->assertJsonPath('data.items.0.name', 'Pizza (Large)')
            ->assertJsonPath('data.items.0.unitPrice', 400)
            ->assertJsonPath('data.items.0.addons.0', 'Extra cheese');

        // A sized product needs a size; an unknown add-on is refused.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid]],
        ])->assertUnprocessable();

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'variant' => 'sm', 'addons' => ['nope']]],
        ])->assertUnprocessable();
    }
}
