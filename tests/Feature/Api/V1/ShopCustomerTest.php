<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\ShopCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's customer book: add, edit (including a mistyped mobile), search,
 * delete, and the per-shop walls around it.
 */
class ShopCustomerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business} */
    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_owner_adds_a_customer_with_only_a_mobile_number(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/customers', ['phone' => '+91 98765-43210'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonPath('data.name', null);

        $this->assertSame(1, $business->shopCustomers()->count());
    }

    public function test_mobile_number_is_required_and_must_be_valid(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/customers', ['name' => 'Asha'])
            ->assertStatus(422);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/customers', ['phone' => '12345'])
            ->assertStatus(422);
    }

    public function test_the_same_mobile_cannot_be_added_twice_to_one_shop(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        ShopCustomer::factory()->create(['business_id' => $business->id, 'phone' => '9876543210']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/customers', ['phone' => '09876543210'])
            ->assertStatus(422);
    }

    public function test_another_shop_can_have_the_same_mobile(): void
    {
        [$owner] = $this->ownerWithBusiness();
        ShopCustomer::factory()->create(['phone' => '9876543210']);

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/business/customers', ['phone' => '9876543210'])
            ->assertCreated();
    }

    public function test_owner_corrects_a_wrong_mobile_and_other_details(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $customer = ShopCustomer::factory()->create(['business_id' => $business->id, 'phone' => '9876543210']);

        $this->withToken($this->token($owner))
            ->putJson("/api/v1/business/customers/{$customer->uuid}", [
                'phone' => '9876543211',
                'name' => 'Asha Rao',
                'email' => 'asha@example.com',
                'city' => 'Indore',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '9876543211')
            ->assertJsonPath('data.city', 'Indore');

        // Saving without changing the mobile isn't a clash with itself.
        $this->withToken($this->token($owner))
            ->putJson("/api/v1/business/customers/{$customer->uuid}", ['phone' => '9876543211'])
            ->assertOk();
    }

    public function test_search_matches_name_or_mobile(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        ShopCustomer::factory()->create(['business_id' => $business->id, 'phone' => '9876543210', 'name' => 'Asha']);
        ShopCustomer::factory()->create(['business_id' => $business->id, 'phone' => '9123456789', 'name' => 'Ravi']);

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/customers?search=asha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Asha');

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/customers?search=91234')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ravi');
    }

    public function test_owner_cannot_see_or_touch_another_shops_customers(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $other = ShopCustomer::factory()->create();

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/business/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->token($owner))
            ->putJson("/api/v1/business/customers/{$other->uuid}", ['phone' => '9000000000'])
            ->assertNotFound();

        $this->withToken($this->token($owner))
            ->deleteJson("/api/v1/business/customers/{$other->uuid}")
            ->assertNotFound();

        $this->assertNotNull($other->fresh());
    }

    public function test_owner_deletes_a_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $customer = ShopCustomer::factory()->create(['business_id' => $business->id]);

        $this->withToken($this->token($owner))
            ->deleteJson("/api/v1/business/customers/{$customer->uuid}")
            ->assertOk();

        $this->assertNull($customer->fresh());
    }
}
