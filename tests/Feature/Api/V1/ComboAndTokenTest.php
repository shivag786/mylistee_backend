<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\CustomerToken;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComboAndTokenTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function actingAsToken(User $user): static
    {
        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_owner_builds_a_combo_with_derived_totals(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $a = Product::factory()->create(['business_id' => $business->id, 'mrp' => 120, 'selling_price' => 100]);
        $b = Product::factory()->create(['business_id' => $business->id, 'mrp' => 90, 'selling_price' => 80]);

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/combos', [
                'name' => 'Combo meal',
                'combo_price' => 150,
                'items' => [
                    ['product_id' => $a->uuid, 'quantity' => 1],
                    ['product_id' => $b->uuid, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.comboPrice', 150)
            ->assertJsonPath('data.totalPrice', 180)  // 100 + 80
            ->assertJsonPath('data.totalMrp', 210)     // 120 + 90
            ->assertJsonPath('data.savings', 30)       // 180 - 150
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('combos', ['business_id' => $business->id, 'name' => 'Combo meal']);
        $this->assertDatabaseCount('combo_items', 2);
    }

    public function test_combo_requires_two_to_three_products(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $a = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/combos', [
                'name' => 'Too small',
                'combo_price' => 100,
                'items' => [['product_id' => $a->uuid]],
            ])
            ->assertStatus(422);
    }

    public function test_combo_rejects_products_from_another_business(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        [, $otherBusiness] = $this->ownerWithBusiness();
        $mine = Product::factory()->create(['business_id' => $business->id]);
        $theirs = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/combos', [
                'name' => 'Sneaky',
                'combo_price' => 100,
                'items' => [
                    ['product_id' => $mine->uuid],
                    ['product_id' => $theirs->uuid],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_owner_can_delete_a_combo(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $combo = Combo::factory()->create(['business_id' => $business->id]);

        $this->actingAsToken($owner)
            ->deleteJson("/api/v1/business/combos/{$combo->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('combos', ['id' => $combo->id]);
    }

    public function test_customer_gets_a_stable_token_that_persists_until_expiry(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);

        $first = $this->actingAsToken($customer)->getJson('/api/v1/wallet/token')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expiresAt', 'expiresInSeconds']])
            ->json('data.token');

        // Same token returned while still valid.
        $second = $this->actingAsToken($customer)->getJson('/api/v1/wallet/token')->json('data.token');
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^\d{5}$/', $first);
    }

    public function test_expired_token_is_replaced(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $service = app(TokenService::class);

        $old = $service->generate($customer);
        $old->update(['expires_at' => Carbon::now()->subMinute()]);

        $current = $service->currentFor($customer);
        $this->assertNotSame($old->token, $current->token);
        $this->assertTrue($current->expires_at->isFuture());
    }

    public function test_owner_can_look_up_a_customer_by_token(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active, 'name' => 'Asha']);
        $token = app(TokenService::class)->generate($customer);

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/token/lookup', ['token' => $token->token])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Asha')
            ->assertJsonPath('data.customer.coinBalance', 0);
    }

    public function test_token_lookup_rejects_unknown_token(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/token/lookup', ['token' => '00000'])
            ->assertStatus(404);
    }

    public function test_stale_customer_token_is_expired_flag(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $token = CustomerToken::create([
            'user_id' => $customer->id,
            'token' => '12345',
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertTrue($token->isExpired());
    }
}
