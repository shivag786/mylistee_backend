<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromotionEngineTest extends TestCase
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

    public function test_owner_creates_a_percentage_smart_offer_and_effective_price_reflects_it(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 200]);

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/promotions', [
                'promotion_type' => 'percentage',
                'name' => '25% off burger',
                'product_id' => $product->uuid,
                'value' => 25,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.productName', $product->name)
            ->assertJsonPath('data.isActiveNow', true);

        // Product list now carries the discounted effective price.
        $this->actingAsToken($owner)
            ->getJson('/api/v1/business/products')
            ->assertOk()
            ->assertJsonPath('data.0.effectivePrice', 150)
            ->assertJsonPath('data.0.activeOffer.name', '25% off burger');
    }

    public function test_flat_discount_applies(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 200]);

        $this->actingAsToken($owner)->postJson('/api/v1/business/promotions', [
            'promotion_type' => 'flat',
            'name' => '₹40 off',
            'product_id' => $product->uuid,
            'value' => 40,
        ])->assertCreated();

        $this->assertSame(160.0, $product->fresh()->load('promotions')->effectivePrice());
    }

    public function test_happy_hour_only_applies_inside_the_daily_window(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        Promotion::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'promotion_type' => 'happy_hour',
            'config' => ['discount_type' => 'percentage', 'value' => 50],
            'status' => PromotionStatus::Running,
            'daily_start_time' => '15:00',
            'daily_end_time' => '18:00',
        ]);

        Carbon::setTestNow(Carbon::parse('today 16:00'));
        $this->assertSame(50.0, $product->fresh()->load('promotions')->effectivePrice());

        Carbon::setTestNow(Carbon::parse('today 20:00'));
        $this->assertSame(100.0, $product->fresh()->load('promotions')->effectivePrice());

        Carbon::setTestNow();
    }

    public function test_bogo_does_not_change_unit_price(): void
    {
        [, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 100]);
        Promotion::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'promotion_type' => 'bogo',
            'config' => ['buy_qty' => 1, 'get_qty' => 1],
            'status' => PromotionStatus::Running,
        ]);

        $this->assertSame(100.0, $product->fresh()->load('promotions')->effectivePrice());
    }

    public function test_future_promotion_is_scheduled_then_tick_starts_it(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id]);

        Carbon::setTestNow(Carbon::parse('2026-07-22 10:00'));
        $this->actingAsToken($owner)->postJson('/api/v1/business/promotions', [
            'promotion_type' => 'percentage',
            'name' => 'Later',
            'product_id' => $product->uuid,
            'value' => 10,
            'starts_at' => '2026-07-22 12:00:00',
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

        // Before start — tick keeps it scheduled.
        app(PromotionService::class)->tick();
        $this->assertSame(PromotionStatus::Scheduled, Promotion::first()->status);

        // After start — tick promotes to running.
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:30'));
        app(PromotionService::class)->tick();
        $this->assertSame(PromotionStatus::Running, Promotion::first()->status);

        Carbon::setTestNow();
    }

    public function test_tick_expires_finished_promotions(): void
    {
        [, $business] = $this->ownerWithBusiness();
        Promotion::factory()->create([
            'business_id' => $business->id,
            'status' => PromotionStatus::Running,
            'ends_at' => Carbon::now()->subHour(),
        ]);

        app(PromotionService::class)->tick();

        $this->assertSame(PromotionStatus::Expired, Promotion::first()->status);
    }

    public function test_owner_can_pause_and_resume(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $promotion = Promotion::factory()->create(['business_id' => $business->id, 'status' => PromotionStatus::Running]);

        $this->actingAsToken($owner)
            ->patchJson("/api/v1/business/promotions/{$promotion->uuid}/status", ['action' => 'pause'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->actingAsToken($owner)
            ->patchJson("/api/v1/business/promotions/{$promotion->uuid}/status", ['action' => 'resume'])
            ->assertOk()
            ->assertJsonPath('data.status', 'running');
    }

    public function test_promotions_are_scoped_to_the_owning_business(): void
    {
        [, $businessA] = $this->ownerWithBusiness();
        [$ownerB] = $this->ownerWithBusiness();
        $promotion = Promotion::factory()->create(['business_id' => $businessA->id]);

        $this->actingAsToken($ownerB)
            ->deleteJson("/api/v1/business/promotions/{$promotion->uuid}")
            ->assertStatus(404);
    }
}
