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
 * The service (fulfilment) layer over the order flow (Phase 7.6): modes,
 * dining tables, and their effect on placed orders. The base order system is
 * unchanged — a shop with no config still behaves as pickup-only.
 */
class ServiceModeTest extends TestCase
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
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_unconfigured_shop_defaults_orders_to_pickup(): void
    {
        [, $business] = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.serviceType', 'pickup')
            ->assertJsonPath('data.serviceLabel', 'Pickup')
            ->assertJsonPath('data.deliveryFee', 0);
    }

    public function test_owner_configures_modes_and_tables_and_they_appear_publicly(): void
    {
        [$owner, $business] = $this->shop();

        $this->token($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup', 'dine_in', 'delivery'],
            'defaultMode' => 'dine_in',
            'deliveryFee' => 25,
        ])->assertOk()
            ->assertJsonPath('data.defaultMode', 'dine_in')
            ->assertJsonPath('data.deliveryFee', 25);

        $table = $this->token($owner)->postJson('/api/v1/business/tables', [
            'label' => 'Table 5',
            'capacity' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.label', 'Table 5')
            ->json('data');

        $this->assertStringContainsString("?table={$table['id']}", $table['qrUrl']);

        // The public profile advertises the modes + active tables to the customer.
        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.service.defaultMode', 'dine_in')
            ->assertJsonPath('data.business.service.deliveryFee', 25)
            ->assertJsonPath('data.business.tables.0.label', 'Table 5');
    }

    public function test_dine_in_order_binds_to_a_table(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $this->token($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup', 'dine_in'],
            'defaultMode' => 'pickup',
        ])->assertOk();
        $tableId = $this->token($owner)->postJson('/api/v1/business/tables', ['label' => 'T1'])
            ->json('data.id');

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'serviceType' => 'dine_in',
            'table' => $tableId,
        ])->assertCreated()
            ->assertJsonPath('data.serviceType', 'dine_in')
            ->assertJsonPath('data.tableLabel', 'T1')
            ->assertJsonPath('data.serviceLabel', 'T1');
    }

    public function test_delivery_adds_the_fee_and_requires_an_address(): void
    {
        [$owner, $business] = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        $this->token($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup', 'delivery'],
            'defaultMode' => 'pickup',
            'deliveryFee' => 40,
        ])->assertOk();

        // Missing address is rejected.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'serviceType' => 'delivery',
        ])->assertStatus(422);

        // With an address, the fee is folded into the total.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'serviceType' => 'delivery',
            'serviceAddress' => '12 MG Road',
        ])->assertCreated()
            ->assertJsonPath('data.serviceType', 'delivery')
            ->assertJsonPath('data.subtotal', 100)
            ->assertJsonPath('data.deliveryFee', 40)
            ->assertJsonPath('data.total', 140)
            ->assertJsonPath('data.serviceAddress', '12 MG Road');
    }

    public function test_a_mode_the_shop_does_not_offer_is_rejected(): void
    {
        [, $business] = $this->shop();
        $customer = $this->customer();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);

        // Shop is pickup-only (unconfigured) — a dine-in order must fail.
        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'serviceType' => 'dine_in',
        ])->assertStatus(422);
    }
}
