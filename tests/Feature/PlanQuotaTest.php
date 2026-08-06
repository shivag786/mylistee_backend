<?php

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PlanLimitService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlanQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    }

    public function test_free_plan_caps_active_combos_at_two(): void
    {
        $business = Business::factory()->create(); // no subscription → free plan
        Combo::factory()->count(2)->create(['business_id' => $business->id, 'is_visible' => true]);

        $limits = app(PlanLimitService::class);
        $this->assertSame(2, $limits->activeCombos($business));

        $this->expectException(ValidationException::class);
        $limits->assertCanActivateCombo($business); // the 3rd active combo is blocked
    }

    public function test_free_plan_caps_active_promotions_at_two(): void
    {
        $business = Business::factory()->create();
        Promotion::factory()->count(2)->create([
            'business_id' => $business->id,
            'status' => PromotionStatus::Running,
        ]);

        $this->expectException(ValidationException::class);
        app(PlanLimitService::class)->assertCanActivatePromotion($business);
    }

    public function test_downgrade_deactivates_excess_without_deleting(): void
    {
        $business = Business::factory()->create();
        $combos = Combo::factory()->count(4)->create(['business_id' => $business->id, 'is_visible' => true]);

        $free = Plan::where('key', 'free')->first(); // max_active_combos = 2
        app(SubscriptionService::class)->subscribe($business, $free);

        // Only the newest 2 stay visible; the rest are hidden, not deleted.
        $this->assertSame(2, $business->combos()->where('is_visible', true)->count());
        $this->assertSame(4, $business->combos()->count(), 'Nothing should be deleted.');

        // The two newest (highest id) remain active.
        $stillActive = $business->combos()->where('is_visible', true)->pluck('id')->sort()->values();
        $this->assertEquals($combos->sortByDesc('id')->take(2)->pluck('id')->sort()->values(), $stillActive);
    }

    public function test_admin_can_create_a_plan_with_quota_limits(): void
    {
        $token = $this->admin()->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/plans', [
                'key' => 'growth',
                'name' => 'Growth',
                'price' => 999,
                'interval' => 'quarter',
                'maxActiveCombos' => 5,
                'maxActivePromotions' => 5,
                'maxPushPerMonth' => 20,
                'features' => ['analytics', 'push_notifications'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'growth')
            ->assertJsonPath('data.interval', 'quarter')
            ->assertJsonPath('data.limits.maxActiveCombos', 5)
            ->assertJsonPath('data.limits.maxActivePromotions', 5);

        $this->assertDatabaseHas('plans', ['key' => 'growth', 'max_active_combos' => 5]);
    }

    public function test_admin_can_update_new_quota_limits(): void
    {
        $token = $this->admin()->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/admin/plans/free', ['maxActiveCombos' => 3])
            ->assertOk()
            ->assertJsonPath('data.limits.maxActiveCombos', 3);
    }

    public function test_create_plan_rejects_duplicate_key(): void
    {
        $token = $this->admin()->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/plans', [
                'key' => 'free', // already exists
                'name' => 'Dupe',
                'price' => 0,
                'interval' => 'month',
            ])
            ->assertStatus(422);
    }
}
