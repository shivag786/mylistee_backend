<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\ServiceSettingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Plans decide modules (with days), free days, menu size and a first-time offer. */
class PlanAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(array $attributes = []): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id] + $attributes);

        return [$owner, $business, Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100])];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function editPlan(string $key, array $payload): void
    {
        $this->token(User::factory()->admin()->create())
            ->patchJson("/api/v1/admin/plans/{$key}", $payload)->assertOk();
    }

    /** @param  array<string, bool|array{on: bool, days?: int|null}>  $overrides */
    private function modules(array $overrides = []): array
    {
        $all = collect(array_keys(config('plan_modules')))->mapWithKeys(fn ($id) => [$id => ['on' => true, 'days' => null]]);

        return $all->merge(collect($overrides)->map(fn ($v) => is_bool($v) ? ['on' => $v, 'days' => null] : $v))->all();
    }

    private function quote(Business $business, Product $product)
    {
        return $this->postJson('/api/v1/orders/quote', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertOk();
    }

    public function test_plans_never_edited_keep_everything_open(): void
    {
        [$owner, $business, $product] = $this->shop();

        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.access.expired', false)
            ->assertJsonPath('data.access.modules.ordering.allowed', true)
            ->assertJsonPath('data.access.modules.combos.allowed', true);
        $this->quote($business, $product)->assertJsonPath('data.acceptingOrders', true);
    }

    public function test_a_module_left_out_of_the_plan_refuses_changes_but_not_reads(): void
    {
        [$owner] = $this->shop();
        $this->editPlan('free', ['modules' => $this->modules(['combos' => false, 'analytics' => false])]);

        $this->token($owner)->postJson('/api/v1/business/combos', [])
            ->assertForbidden()
            ->assertJsonPath('errors.module.0', 'combos');
        $this->token($owner)->getJson('/api/v1/business/combos')->assertOk();
        // Insight-only modules lock reads too.
        $this->token($owner)->getJson('/api/v1/business/analytics')->assertForbidden();
        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.access.modules.combos.allowed', false)
            ->assertJsonPath('data.access.modules.combos.reason', 'plan');
    }

    public function test_free_days_count_from_when_they_are_set_and_then_the_shop_is_menu_only(): void
    {
        // A shop that signed up long ago still gets the full free days.
        [$owner, $business, $product] = $this->shop(['created_at' => now()->subDays(200)]);
        $this->editPlan('free', ['durationDays' => 15]);

        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.access.expired', false)
            ->assertJsonPath('data.access.daysLeft', 15);

        Carbon::setTestNow(now()->addDays(16));

        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.access.expired', true)
            ->assertJsonPath('data.access.modules.ordering.reason', 'expired');

        // Customers still see the shop and its menu — as a catalogue.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk()->assertJsonPath('data.business.ordersPaused', true);
        $this->quote($business, $product)->assertJsonPath('data.acceptingOrders', false);
        $this->token($this->customer())->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertUnprocessable();

        // The menu itself stays the owner's to manage.
        $this->token($owner)->getJson('/api/v1/business/products')->assertOk();
    }

    public function test_a_module_can_be_given_for_a_number_of_days(): void
    {
        [, $business, $product] = $this->shop();
        $this->editPlan('free', ['modules' => $this->modules(['ordering' => ['on' => true, 'days' => 3]])]);

        $this->quote($business, $product)->assertJsonPath('data.acceptingOrders', true);

        Carbon::setTestNow(now()->addDays(4));
        $this->app['auth']->forgetGuards();
        $this->quote($business, $product)->assertJsonPath('data.acceptingOrders', false);
    }

    public function test_delivery_and_ways_to_pay_follow_the_plan(): void
    {
        [, $business, $product] = $this->shop();
        app(ServiceSettingService::class)->update($business, ['pickup', 'delivery'], 'pickup', 20.0);

        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk()
            ->assertJsonPath('data.business.service.modes', ['pickup', 'delivery']);

        $this->editPlan('free', ['modules' => $this->modules(['delivery' => false])]);
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertOk()
            ->assertJsonPath('data.business.service.modes', ['pickup']);
        $this->token($this->customer())->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'serviceType' => 'delivery',
            'serviceAddress' => '12 Main Road',
        ])->assertUnprocessable()->assertJsonValidationErrors('serviceType');

        // No counter payments and no gateway: nothing to pay with, so no ordering.
        $this->editPlan('free', ['modules' => $this->modules(['pay_counter' => false])]);
        $this->app['auth']->forgetGuards();
        $this->quote($business, $product)->assertJsonPath('data.acceptingOrders', false);
    }

    public function test_the_first_time_offer_is_charged_once_for_its_own_days(): void
    {
        [$owner, $business] = $this->shop();
        $this->editPlan('pro', [
            'introPrice' => 149,
            'introDays' => 90,
            'introEndsAt' => now()->addDays(10)->toIso8601String(),
            'introLabel' => 'First time only',
        ]);

        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.introEligible', ['pro']);
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonPath('data.2.intro.price', 149);

        $this->token($owner)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertOk()
            ->assertJsonPath('data.access.daysLeft', 90)
            ->assertJsonPath('data.introEligible', []);
        $this->token($owner)->getJson('/api/v1/business/invoices')->assertOk()->assertJsonPath('data.0.amount', 149);

        // A second shop of the same owner does not get it again.
        $second = Business::factory()->create(['owner_id' => $owner->id]);
        $this->assertFalse(app(\App\Services\PlanAccessService::class)->introEligible($second, Plan::where('key', 'pro')->sole()));
    }

    public function test_an_early_renewal_adds_to_the_days_left(): void
    {
        [$owner] = $this->shop();
        $this->editPlan('pro', ['durationDays' => 30]);

        $this->token($owner)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertOk()
            ->assertJsonPath('data.access.daysLeft', 30);
        Carbon::setTestNow(now()->addDays(10));
        $this->token($owner)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertOk()
            ->assertJsonPath('data.access.daysLeft', 50);
    }

    public function test_a_plan_can_last_months_and_setting_months_clears_the_days(): void
    {
        [$owner] = $this->shop();
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));
        $this->editPlan('pro', ['durationDays' => 30]);
        $this->editPlan('pro', ['durationMonths' => 3, 'durationDays' => null]);

        $plan = Plan::where('key', 'pro')->sole();
        $this->assertNull($plan->duration_days);
        $this->assertSame(3, $plan->duration_months);

        $this->token($owner)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertOk()
            ->assertJsonPath('data.subscription.endsAt', Carbon::parse('2026-04-30 10:00:00')->toIso8601String());
    }

    public function test_an_inactive_plan_cannot_be_bought_but_shops_on_it_keep_it(): void
    {
        [$owner, $business] = $this->shop();
        $this->token($owner)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertOk();
        $endsAt = $business->fresh()->activeSubscription()->ends_at;

        $this->editPlan('pro', ['isActive' => false]);

        // Gone from the pricing list, and nobody can take it up…
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonMissing(['key' => 'pro']);
        [$other] = $this->shop();
        $this->token($other)->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])->assertStatus(422);

        // …but the shop already on it keeps the same plan and end date.
        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.plan.key', 'pro');
        $this->assertTrue($business->fresh()->activeSubscription()->ends_at->equalTo($endsAt));

        // The default plan can't be switched off.
        $this->token(User::factory()->admin()->create())
            ->patchJson('/api/v1/admin/plans/free', ['isActive' => false])->assertStatus(422);
    }

    public function test_the_plan_caps_how_many_products_a_menu_has(): void
    {
        [$owner] = $this->shop();
        $this->editPlan('free', ['maxProducts' => 1]);

        $this->token($owner)->post('/api/v1/business/products', ['name' => 'Tea', 'selling_price' => 10])
            ->assertUnprocessable();
        $this->token($owner)->getJson('/api/v1/business/subscription')->assertOk()
            ->assertJsonPath('data.usage.products.used', 1)
            ->assertJsonPath('data.usage.products.limit', 1);
    }
}
