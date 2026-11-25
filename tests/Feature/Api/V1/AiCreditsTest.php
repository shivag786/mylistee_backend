<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiCreditPack;
use App\Models\AiUsage;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI answers per plan per month, add-on packs bought with Razorpay, the admin's usage report. */
class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'secret_test';

    private const ORDER_ID = 'order_AI123';

    private const PAYMENT_ID = 'pay_AI123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['razorpay.key_id' => 'rzp_test_key', 'razorpay.key_secret' => self::KEY_SECRET, 'razorpay.webhook_secret' => 'whsec_test']);
        app(SettingService::class)->set(['aiEnabled' => true, 'geminiApiKey' => 'AQ.test']);
        $modules = collect(array_keys(config('plan_modules')))->mapWithKeys(fn ($id) => [$id => ['on' => true, 'days' => null]])->all();
        $this->as($this->admin())->patchJson('/api/v1/admin/plans/free', ['modules' => $modules, 'aiAnswersPerMonth' => 2])->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Chai Point'])];
    }

    /** @param  list<array<string, mixed>>  $ideas */
    private function gemini(array $ideas): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(['suggestions' => $ideas])]]]]]];
    }

    private function fakeAll(int $amountPaise = 9900): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->gemini([
                ['title' => 'One', 'body' => 'a'], ['title' => 'Two', 'body' => 'b'], ['title' => 'Three', 'body' => 'c'],
                ['title' => 'Four', 'body' => 'd'], ['title' => 'Five', 'body' => 'e'],
            ])),
            'api.razorpay.com/v1/orders' => Http::response(['id' => self::ORDER_ID, 'amount' => $amountPaise, 'currency' => 'INR', 'status' => 'created']),
            'api.razorpay.com/v1/payments/*' => Http::response([
                'id' => self::PAYMENT_ID, 'order_id' => self::ORDER_ID, 'amount' => $amountPaise, 'currency' => 'INR', 'status' => 'captured', 'method' => 'upi',
            ]),
        ]);
    }

    public function test_plan_answers_first_then_bought_answers_then_stop(): void
    {
        $this->fakeAll();
        [$owner, $business] = $this->shop();
        $business->forceFill(['ai_credits' => 1])->save();

        $ask = fn () => $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'sales_tips']);

        $ask()->assertOk()->assertJsonPath('data.planUsed', 1)->assertJsonPath('data.left', 2);
        $ask()->assertOk()->assertJsonPath('data.planUsed', 2)->assertJsonPath('data.credits', 1);
        $ask()->assertOk()->assertJsonPath('data.addonUsed', 1)->assertJsonPath('data.credits', 0)->assertJsonPath('data.left', 0);
        $ask()->assertStatus(422)->assertJsonValidationErrors('limit', 'errors');

        $this->assertSame(2, AiUsage::query()->where('source', 'plan')->count());
        $this->assertSame(1, AiUsage::query()->where('source', 'addon')->count());
    }

    public function test_ideas_per_answer_is_set_by_the_admin(): void
    {
        $this->fakeAll();
        [$owner] = $this->shop();

        $this->as($this->admin())->putJson('/api/v1/admin/ai/settings', ['ideasPerAnswer' => 2])->assertOk();
        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'offer_ideas'])
            ->assertOk()
            ->assertJsonCount(2, 'data.suggestions');
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'Suggest 2 offers'));

        $this->as($this->admin())->putJson('/api/v1/admin/ai/settings', ['ideasPerAnswer' => 11])->assertStatus(422);
    }

    public function test_owner_buys_a_pack_with_razorpay(): void
    {
        $this->fakeAll(9900);
        [$owner, $business] = $this->shop();
        $admin = $this->admin();

        $packId = $this->as($admin)->postJson('/api/v1/admin/ai/packs', ['name' => 'Starter pack', 'answers' => 50, 'price' => 99])
            ->assertCreated()->json('data.id');
        $this->as($admin)->postJson('/api/v1/admin/ai/packs', ['name' => 'Old pack', 'answers' => 10, 'price' => 20, 'isActive' => false])->assertCreated();

        $this->as($owner)->getJson('/api/v1/business/ai-credits')
            ->assertOk()
            ->assertJsonCount(1, 'data.packs')
            ->assertJsonPath('data.packs.0.answers', 50)
            ->assertJsonPath('data.paymentsLive', true);

        $this->as($owner)->postJson('/api/v1/business/ai-credits/checkout', ['packId' => $packId])
            ->assertOk()
            ->assertJsonPath('data.orderId', self::ORDER_ID)
            ->assertJsonPath('data.amount', 9900);

        $verify = fn () => $this->as($owner)->postJson('/api/v1/business/ai-credits/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => hash_hmac('sha256', self::ORDER_ID.'|'.self::PAYMENT_ID, self::KEY_SECRET),
        ]);
        $verify()->assertOk()->assertJsonPath('data.credited', 50)->assertJsonPath('data.credits', 50);
        $verify()->assertOk()->assertJsonPath('data.credited', 0)->assertJsonPath('data.credits', 50); // idempotent

        $this->assertSame(50, $business->fresh()->ai_credits);
        $this->as($owner)->getJson('/api/v1/business/ai-credits')->assertJsonCount(1, 'data.purchases');

        $this->as($admin)->getJson('/api/v1/admin/ai')
            ->assertOk()
            ->assertJsonPath('data.packSales.monthAmount', 99)
            ->assertJsonPath('data.packSales.creditsOutstanding', 50);
    }

    public function test_the_webhook_credits_a_pack_when_the_tab_closed(): void
    {
        $this->fakeAll(9900);
        [$owner, $business] = $this->shop();
        $pack = AiCreditPack::query()->create(['name' => 'Pack', 'answers' => 30, 'price' => 99]);
        $this->as($owner)->postJson('/api/v1/business/ai-credits/checkout', ['packId' => $pack->uuid])->assertOk();

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => self::PAYMENT_ID, 'order_id' => self::ORDER_ID, 'amount' => 9900, 'status' => 'captured', 'method' => 'upi',
        ]]]]);
        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], $body)->assertOk()->assertJsonPath('data.result', 'captured');

        $this->assertSame(30, $business->fresh()->ai_credits);
    }

    public function test_packs_need_the_ai_module(): void
    {
        $this->fakeAll();
        [$owner] = $this->shop();
        $pack = AiCreditPack::query()->create(['name' => 'Pack', 'answers' => 30, 'price' => 99]);
        $modules = collect(array_keys(config('plan_modules')))
            ->mapWithKeys(fn ($id) => [$id => ['on' => $id !== 'ai_assistant', 'days' => null]])->all();
        $this->as($this->admin())->patchJson('/api/v1/admin/plans/free', ['modules' => $modules])->assertOk();

        $this->as($owner)->postJson('/api/v1/business/ai-credits/checkout', ['packId' => $pack->uuid])->assertForbidden();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'razorpay'));
    }

    public function test_admin_usage_report_and_giving_answers(): void
    {
        $this->fakeAll();
        [$owner, $business] = $this->shop();
        $admin = $this->admin();
        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'sales_tips'])->assertOk();

        $this->as($admin)->postJson('/api/v1/admin/ai/credits', ['businessId' => $business->uuid, 'answers' => 25, 'note' => 'Launch gift'])
            ->assertOk()->assertJsonPath('data.credits', 25);
        $this->as($admin)->postJson('/api/v1/admin/ai/credits', ['businessId' => $business->uuid, 'answers' => -40])
            ->assertOk()->assertJsonPath('data.credits', 0);

        $this->as($admin)->getJson('/api/v1/admin/ai')
            ->assertOk()
            ->assertJsonPath('data.today', 1)
            ->assertJsonPath('data.bySource.plan', 1)
            ->assertJsonPath('data.shops.0.name', 'Chai Point')
            ->assertJsonPath('data.shops.0.planLimit', 2)
            ->assertJsonPath('data.shops.0.planUsed', 1)
            ->assertJsonCount(14, 'data.daily');

        $this->as($owner)->getJson('/api/v1/admin/ai')->assertForbidden();
        $this->assertSame(Plan::where('key', 'free')->value('ai_answers_per_month'), 2);
    }
}
