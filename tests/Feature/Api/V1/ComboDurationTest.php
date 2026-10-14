<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Time-limited combos: the owner picks how long a combo runs, customers see
 * when it ends (for the countdown), and an ended combo can't be ordered.
 */
class ComboDurationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_owner_sets_a_duration_and_can_clear_it(): void
    {
        [$owner, $business] = $this->shop();
        $a = Product::factory()->create(['business_id' => $business->id]);
        $b = Product::factory()->create(['business_id' => $business->id]);
        $endsAt = now()->addDays(2)->startOfMinute();

        $id = $this->token($owner)->postJson('/api/v1/business/combos', [
            'name' => 'Weekend combo',
            'combo_price' => 99,
            'ends_at' => $endsAt->toIso8601String(),
            'auto_disable' => true,
            'items' => [['product_id' => $a->uuid], ['product_id' => $b->uuid]],
        ])->assertCreated()
            ->assertJsonPath('data.endsAt', $endsAt->toIso8601String())
            ->assertJsonPath('data.isActiveNow', true)
            ->json('data.id');

        // "Always": an empty ends_at clears the end date.
        $this->token($owner)->postJson("/api/v1/business/combos/{$id}", ['_method' => 'PUT', 'ends_at' => ''])
            ->assertOk()
            ->assertJsonPath('data.endsAt', null);
    }

    public function test_home_feed_carries_the_end_time_and_hides_ended_combos(): void
    {
        [, $business] = $this->shop();
        $endsAt = now()->addHours(5)->startOfMinute();
        Combo::factory()->create(['business_id' => $business->id, 'name' => 'Ending soon', 'ends_at' => $endsAt]);
        Combo::factory()->create(['business_id' => $business->id, 'name' => 'Already over', 'ends_at' => now()->subMinute()]);

        $this->getJson('/api/v1/combos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ending soon')
            ->assertJsonPath('data.0.endsAt', $endsAt->toIso8601String());
    }

    public function test_an_ended_combo_cannot_be_ordered(): void
    {
        [, $business] = $this->shop();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $combo = Combo::factory()->create(['business_id' => $business->id, 'ends_at' => now()->subMinute()]);

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'combo', 'id' => $combo->uuid, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }
}
