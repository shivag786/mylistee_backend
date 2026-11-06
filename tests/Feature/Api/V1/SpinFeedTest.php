<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Offer;
use App\Models\Spin;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spin & win on the customer home: shops with a live wheel, and their counts. */
class SpinFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_shops_with_a_live_wheel_with_counts(): void
    {
        $wheel = Business::factory()->create(['total_rewards' => 3]);
        Offer::factory()->count(2)->create(['business_id' => $wheel->id]);
        $archived = Business::factory()->create();
        Offer::factory()->archived()->create(['business_id' => $archived->id]);
        Business::factory()->create();

        foreach (User::factory()->count(2)->create() as $u) {
            Spin::create(['customer_id' => $u->id, 'business_id' => $wheel->id]);
        }

        $res = $this->getJson('/api/v1/spins')->assertOk();

        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $wheel->slug)
            ->assertJsonCount(2, 'data.0.offers')
            ->assertJsonPath('data.0.players', 2)
            ->assertJsonPath('data.0.wins', 3)
            ->assertJsonPath('data.0.alreadySpunToday', null)
            ->assertJsonPath('meta.showCounts', true);
    }

    public function test_counts_hidden_when_the_admin_turns_them_off(): void
    {
        $wheel = Business::factory()->create();
        Offer::factory()->create(['business_id' => $wheel->id]);
        app(SettingService::class)->set(['spinShowCounts' => false]);

        $row = $this->getJson('/api/v1/spins')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('players', $row);
        $this->assertFalse($this->getJson('/api/v1/config')->json('data.spin.showCounts'));
    }

    public function test_signed_in_customer_sees_todays_spin_used(): void
    {
        $wheel = Business::factory()->create();
        Offer::factory()->create(['business_id' => $wheel->id]);
        $customer = User::factory()->create();
        Spin::create(['customer_id' => $customer->id, 'business_id' => $wheel->id]);

        $this->withToken($customer->createToken('api')->plainTextToken)
            ->getJson('/api/v1/spins')
            ->assertOk()
            ->assertJsonPath('data.0.alreadySpunToday', true);
    }

    public function test_admin_can_switch_counts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/settings', ['spinShowCounts' => false])
            ->assertOk()
            ->assertJsonPath('data.spinShowCounts', false);
    }
}
