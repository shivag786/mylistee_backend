<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\AiCampaignApproved;
use App\Models\AiSuggestion;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Coupon;
use App\Models\FeatureFlag;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\ShopPushCampaign;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI Sales Manager + AI Customer Growth Advisor: safety rules, approve, push. */
class AiAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $shop;

    /** @var array<string, Product> */
    private array $menu;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Kolkata']);
        // 11 AM in the shop's time zone — outside push quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-10-14 11:00:00', 'Asia/Kolkata')->utc());
        app(SettingService::class)->set(['aiEnabled' => true, 'geminiApiKey' => 'AQ.test']);

        $this->owner = User::factory()->businessOwner()->create();
        $this->shop = Business::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Chai Point']);
        foreach (['Chai' => 20, 'Samosa' => 30, 'Paneer Roll' => 120, 'Cold Coffee' => 90] as $name => $price) {
            $this->menu[$name] = Product::factory()->create(['business_id' => $this->shop->id, 'name' => $name, 'selling_price' => $price, 'is_visible' => true]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** Paid orders with Chai + Samosa together, by named customers. */
    private function orders(int $n, ?Carbon $at = null, ?User $customer = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $order = Order::factory()->create([
                'business_id' => $this->shop->id,
                'customer_id' => ($customer ?? User::factory()->create(['name' => 'Secret Customer '.$i, 'role' => UserRole::Customer]))->id,
                'status' => OrderStatus::Paid,
                'subtotal' => 50, 'total' => 50,
                'created_at' => $at ?? now()->subDays(2),
            ]);
            foreach (['Chai' => 20, 'Samosa' => 30] as $name => $price) {
                DB::table('order_items')->insert([
                    'order_id' => $order->id, 'product_id' => $this->menu[$name]->id, 'item_type' => 'product',
                    'name' => $name, 'unit_price' => $price, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function fakeAi(array $suggestions): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['suggestions' => $suggestions])]]]]],
        ])]);
    }

    public function test_sales_ideas_obey_the_safety_rules_and_send_no_personal_data(): void
    {
        $this->orders(5);
        $chai = $this->menu['Chai']->id;
        $samosa = $this->menu['Samosa']->id;
        $this->fakeAi([
            ['type' => 'percent', 'title' => 'Big chai sale', 'message' => 'Chai pe 45% off!', 'product_ids' => [$chai, 99999], 'value' => 45, 'validity_days' => 60],
            ['type' => 'combo', 'title' => 'Chai 2 Samosa combo', 'message' => 'Chai + 2 Samosa sirf ₹60', 'items' => [['id' => $chai, 'qty' => 1], ['id' => $samosa, 'qty' => 2]], 'value' => 60, 'time_window' => '16:00-18:00'],
            ['type' => 'bogo', 'title' => 'Samosa BOGO', 'message' => '1 lo 1 free', 'product_ids' => [$samosa], 'buy_qty' => 1, 'get_qty' => 1],
            ['type' => 'happy_hour', 'title' => 'Ghost item hour', 'product_ids' => [424242], 'value' => 10, 'time_window' => '16:00-18:00'],
            ['type' => 'free_money', 'title' => 'Nope'],
        ]);

        $res = $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'sales'])
            ->assertOk()
            ->assertJsonCount(3, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.details.value', 30)
            ->assertJsonPath('data.suggestions.0.details.productIds', [$chai])
            ->assertJsonPath('data.suggestions.0.details.validityDays', 30)
            ->assertJsonPath('data.suggestions.1.details.value', 64)   // 80% of ₹80 (chai + 2 samosa)
            ->assertJsonPath('data.suggestions.1.message', 'Chai + 2 Samosa sirf ₹64')
            ->assertJsonPath('data.suggestions.1.details.timeFrom', null)
            ->assertJsonPath('data.suggestions.2.details.buyQty', 3)   // 1 free in 4 = 25%
            ->assertJsonPath('data.suggestions.2.details.getQty', 1)
            ->assertJsonPath('data.stats.orders', 5);
        $this->assertNotEmpty($res->json('data.suggestions.0.details.adjustments'));

        Http::assertSent(function ($r) {
            $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'Chai Point') && str_contains($body, 'Samosa') && ! str_contains($body, 'Secret Customer');
        });
    }

    public function test_approving_creates_real_offers_combos_and_coupons(): void
    {
        $this->orders(5);
        $chai = $this->menu['Chai']->id;
        $samosa = $this->menu['Samosa']->id;
        $this->fakeAi([
            ['type' => 'happy_hour', 'title' => 'Chai happy hour', 'message' => 'Shaam 4-6 chai pe 20% off', 'product_ids' => [$chai], 'value' => 20, 'time_window' => '4 pm to 6 pm', 'days' => ['monday', 'tue'], 'validity_days' => 7],
            ['type' => 'combo', 'title' => 'Chai Samosa combo', 'product_ids' => [$chai, $samosa], 'value' => 45],
            ['type' => 'flat', 'title' => '₹40 off bill', 'product_ids' => [], 'value' => 40, 'min_order' => 100],
        ]);
        $ideas = $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'sales'])->json('data.suggestions');

        // Happy hour → one promotion per product, with time window and weekdays.
        $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[0]['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $promo = Promotion::where('product_id', $chai)->firstOrFail();
        $this->assertSame('happy_hour', $promo->promotion_type->value);
        $this->assertEquals(20, $promo->config['value']);
        $this->assertSame(['mon', 'tue'], $promo->config['days']);
        $this->assertSame('16:00', substr((string) $promo->daily_start_time, 0, 5));

        // Combo, with the owner's edited price — still floored at 80%.
        $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[1]['id']}/approve", ['value' => 10])->assertOk();
        $combo = Combo::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertEquals(40, (float) $combo->combo_price);
        $this->assertCount(2, $combo->items);

        // ₹40 off the bill → coupon whose minimum bill keeps it within 30%.
        $res = $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[2]['id']}/approve")->assertOk();
        $coupon = Coupon::where('business_id', $this->shop->id)->where('code', $res->json('data.result.coupon'))->firstOrFail();
        $this->assertEquals(140, (float) $coupon->min_bill);
        $this->assertSame('flat', $coupon->discount_type);

        // Approving twice is refused.
        $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[2]['id']}/approve")->assertStatus(422);
    }

    public function test_happy_hour_weekdays_switch_the_promotion_off_on_other_days(): void
    {
        $promo = Promotion::factory()->create([
            'business_id' => $this->shop->id, 'product_id' => $this->menu['Chai']->id,
            'status' => 'running', 'config' => ['discount_type' => 'percentage', 'value' => 10, 'days' => ['mon']],
            'daily_start_time' => null, 'daily_end_time' => null, 'starts_at' => null, 'ends_at' => null,
        ]);
        $this->assertFalse($promo->isActiveNow(Carbon::parse('2026-10-14 11:00', 'Asia/Kolkata'))); // Wednesday
        $this->assertTrue($promo->isActiveNow(Carbon::parse('2026-10-12 11:00', 'Asia/Kolkata')));  // Monday
    }

    public function test_growth_campaign_creates_coupon_fires_event_and_sends_push(): void
    {
        FeatureFlag::where('key', 'owner_notifications')->update(['enabled' => true]);
        // A regular who stopped coming: ordered 45 days ago, nothing since.
        $lapsed = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $this->orders(1, now()->subDays(45), $lapsed);
        $this->orders(4);
        $this->fakeAi([
            ['type' => 'win_back', 'title' => 'Bring back regulars', 'segment' => 'lapsed', 'push_title' => 'Aapki yaad aayi!',
                'message' => 'Wapas aao, code {CODE} se 50% off', 'offer' => ['type' => 'percent', 'value' => 50, 'validity_days' => 5],
                'best_send_time' => '17:30', 'reasoning' => '1 lapsed customer', 'expected_result' => '1 comes back'],
            ['type' => 'review_request', 'title' => 'Ask for reviews', 'segment' => 'customers', 'message' => 'Kaisa laga khana? Review dijiye 🙏', 'best_send_time' => '11:00'],
        ]);

        $res = $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'growth'])
            ->assertOk()
            ->assertJsonPath('data.stats.lapsedCustomers', 1)
            ->assertJsonPath('data.suggestions.0.details.offer.value', 30);
        $ideas = $res->json('data.suggestions');

        // Win-back: coupon now, push at the best time (17:30 today) — not yet sent.
        $approved = Event::fakeFor(function () use ($ideas) {
            $res = $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[0]['id']}/approve")->assertOk();
            Event::assertDispatched(AiCampaignApproved::class);

            return $res;
        }, [AiCampaignApproved::class]);
        $code = $approved->json('data.result.coupon');
        $this->assertStringContainsString($code, $approved->json('data.message'));
        $this->assertSame(30.0, (float) Coupon::where('code', $code)->value('value'));
        $this->assertSame('17:30', Carbon::parse($approved->json('data.sendAt'))->setTimezone('Asia/Kolkata')->format('H:i'));

        // The scheduler sends it once the time comes, to the lapsed segment only.
        Carbon::setTestNow(Carbon::parse('2026-10-14 17:31:00', 'Asia/Kolkata')->utc());
        $this->artisan('ai:campaigns-send')->assertSuccessful();
        $campaign = ShopPushCampaign::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame('lapsed', $campaign->audience);
        $this->assertSame(1, $campaign->recipients);
        $this->assertNotNull(AiSuggestion::where('uuid', $ideas[0]['id'])->value('sent_at'));

        // Second campaign the same day: the push system allows one a day → moved to tomorrow 10:00.
        $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$ideas[1]['id']}/approve", ['sendNow' => true])->assertOk();
        $s = AiSuggestion::where('uuid', $ideas[1]['id'])->firstOrFail();
        $this->assertNull($s->sent_at);
        $this->assertSame('scheduled', $s->result['push']['status']);
        $this->assertSame('2026-10-15 10:00', $s->send_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
    }

    public function test_limits_data_and_plan(): void
    {
        // Too few orders.
        $this->orders(2);
        $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'sales'])
            ->assertStatus(422)->assertJsonValidationErrors('orders', 'errors');

        // Three refreshes a day.
        $this->orders(3);
        $this->fakeAi([['type' => 'percent', 'title' => 'Chai 10%', 'product_ids' => [$this->menu['Chai']->id], 'value' => 10]]);
        foreach (range(1, 3) as $_) {
            $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'sales'])->assertOk();
        }
        $this->as($this->owner)->postJson('/api/v1/business/ai/advisor/refresh', ['kind' => 'sales'])->assertStatus(422);
        // Only the newest batch is pending; older ones expired.
        $this->assertSame(1, AiSuggestion::where('status', 'pending')->count());

        // Dismiss.
        $id = AiSuggestion::where('status', 'pending')->value('uuid');
        $this->as($this->owner)->postJson("/api/v1/business/ai/advisor/{$id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');

        // Someone else's idea is not found.
        $other = User::factory()->businessOwner()->create();
        Business::factory()->create(['owner_id' => $other->id]);
        $this->as($other)->postJson("/api/v1/business/ai/advisor/{$id}/approve")->assertNotFound();
    }

    public function test_nightly_run_generates_for_eligible_shops(): void
    {
        $this->orders(5);
        $quiet = Business::factory()->create(); // no orders → skipped
        $this->fakeAi([['type' => 'percent', 'title' => 'Chai 10%', 'product_ids' => [$this->menu['Chai']->id], 'value' => 10],
            ['type' => 'loyalty', 'title' => 'Reward regulars', 'segment' => 'customers', 'message' => 'Har 5th chai free!']]);

        $this->artisan('ai:advisor-nightly', ['--delay' => 0])->assertSuccessful();

        $this->assertTrue(AiSuggestion::where('business_id', $this->shop->id)->where('kind', 'sales')->exists());
        $this->assertTrue(AiSuggestion::where('business_id', $this->shop->id)->where('kind', 'growth')->exists());
        $this->assertFalse(AiSuggestion::where('business_id', $quiet->id)->exists());
    }
}
