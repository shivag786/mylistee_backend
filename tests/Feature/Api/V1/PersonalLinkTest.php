<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Personal link: free trial once, paid day packs that stack, daily expiry reminders. */
class PersonalLinkTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'rzp_test_secret';

    private const ORDER_ID = 'order_PLINK123';

    private const PAYMENT_ID = 'pay_PLINK123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'razorpay.key_id' => 'rzp_test_key',
            'razorpay.key_secret' => self::KEY_SECRET,
            'razorpay.webhook_secret' => 'whsec_test',
        ]);
    }

    /** @return array{0: User, 1: Business} */
    private function owner(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    private function fakeGateway(int $paise): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => self::ORDER_ID, 'amount' => $paise, 'currency' => 'INR', 'status' => 'created']),
            'api.razorpay.com/v1/payments/*' => Http::response([
                'id' => self::PAYMENT_ID, 'order_id' => self::ORDER_ID, 'amount' => $paise, 'status' => 'captured', 'method' => 'upi',
            ]),
        ]);
    }

    public function test_enabling_starts_a_seven_day_trial_once_and_turns_on_store_mode(): void
    {
        [$owner, $business] = $this->owner();
        $this->actingAs($owner);

        $this->getJson('/api/v1/business/personal-link')
            ->assertOk()
            ->assertJsonPath('data.trialAvailable', true)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.packs.0.price', 150)
            ->assertJsonPath('data.packs.2.price', 1499)
            ->assertJsonPath('data.packs.2.regularPrice', 1825);

        $this->putJson('/api/v1/business/personal-link', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.daysLeft', 7)
            ->assertJsonPath('data.onTrial', true)
            ->assertJsonPath('data.trialAvailable', false);

        $this->getJson("/api/v1/businesses/{$business->slug}")->assertJsonPath('data.business.storeMode', true);

        // Off and on again after the trial ran out: no second trial.
        $this->putJson('/api/v1/business/personal-link', ['enabled' => false])->assertJsonPath('data.active', false);
        $this->travel(8)->days();
        $this->putJson('/api/v1/business/personal-link', ['enabled' => true])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.daysLeft', 0);
    }

    public function test_buying_a_pack_adds_days_on_top_of_what_is_left(): void
    {
        [$owner, $business] = $this->owner();
        $this->actingAs($owner);
        $this->putJson('/api/v1/business/personal-link', ['enabled' => true]); // 7 trial days

        $this->fakeGateway(15000);
        $this->postJson('/api/v1/business/personal-link/checkout', ['pack' => 'month'])
            ->assertOk()
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonPath('data.orderId', self::ORDER_ID);

        $this->postJson('/api/v1/business/personal-link/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => hash_hmac('sha256', self::ORDER_ID.'|'.self::PAYMENT_ID, self::KEY_SECRET),
        ])
            ->assertOk()
            ->assertJsonPath('data.daysLeft', 37)
            ->assertJsonPath('data.onTrial', false);

        $payment = Payment::where('gateway_order_id', self::ORDER_ID)->first();
        $this->assertSame('personal_link', $payment->purpose);
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertNull($payment->plan_id);

        // The admin payments list names it (it has no plan).
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.planName', 'Personal link · 30 days');
        $this->actingAs($owner);

        // Verifying again does not add the days twice.
        $this->postJson('/api/v1/business/personal-link/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => hash_hmac('sha256', self::ORDER_ID.'|'.self::PAYMENT_ID, self::KEY_SECRET),
        ])->assertJsonPath('data.daysLeft', 37);
    }

    public function test_a_bad_signature_adds_nothing(): void
    {
        [$owner] = $this->owner();
        $this->actingAs($owner);
        $this->fakeGateway(39900);
        $this->postJson('/api/v1/business/personal-link/checkout', ['pack' => 'quarter'])->assertOk();

        $this->postJson('/api/v1/business/personal-link/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => 'forged',
        ])->assertStatus(422);

        $this->getJson('/api/v1/business/personal-link')->assertJsonPath('data.active', false);
        $this->assertSame(PaymentStatus::Failed, Payment::first()->status);
    }

    public function test_reminders_go_out_once_a_day_only_when_the_admin_enabled_them(): void
    {
        [$owner, $business] = $this->owner();
        $business->forceFill(['personal_link_enabled' => true, 'personal_link_expires_at' => now()->addDays(2)->subHour()])->save();

        $this->artisan('personal-link:remind')->assertSuccessful();
        $this->assertSame(0, Notification::count(), 'off by default');

        app(SettingService::class)->set(['personalLinkReminderEnabled' => true]);

        $this->artisan('personal-link:remind')->assertSuccessful();
        $this->artisan('personal-link:remind')->assertSuccessful();
        $this->assertSame(1, Notification::where('user_id', $owner->id)->count(), 'once a day');
        $this->assertStringContainsString('2 days', Notification::first()->title);

        $this->travel(1)->days();
        $this->artisan('personal-link:remind')->assertSuccessful();
        $this->assertSame(2, Notification::count());
    }

    public function test_shops_far_from_expiry_are_not_reminded(): void
    {
        [, $business] = $this->owner();
        $business->forceFill(['personal_link_enabled' => true, 'personal_link_expires_at' => now()->addDays(10)])->save();
        app(SettingService::class)->set(['personalLinkReminderEnabled' => true]);

        $this->artisan('personal-link:remind')->assertSuccessful();
        $this->assertSame(0, Notification::count());
    }

    public function test_admin_gives_a_free_pack_that_stacks_on_the_days_left_and_is_recorded(): void
    {
        [$owner, $business] = $this->owner();
        $business->forceFill(['personal_link_enabled' => true, 'personal_link_trial_used_at' => now(), 'personal_link_expires_at' => now()->addDays(10)])->save();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/personal-links/{$business->uuid}/grant", [
            'pack' => 'month', 'mode' => 'free', 'amount' => 150, 'note' => 'Cash received at shop',
        ])
            ->assertOk()
            ->assertJsonPath('data.state.daysLeft', 40)
            ->assertJsonPath('data.grant.source', 'admin_free')
            ->assertJsonPath('data.grant.amount', 150)
            ->assertJsonPath('data.state.history.0.grantedBy', $admin->name);

        $grant = \App\Models\PersonalLinkGrant::first();
        $this->assertSame(10, (int) round(now()->diffInDays($grant->expires_before)));
        $this->assertSame(40, (int) round(now()->diffInDays($grant->expires_after)));

        // Custom days work too, and the owner sees the history.
        $this->postJson("/api/v1/admin/personal-links/{$business->uuid}/grant", ['pack' => 'custom', 'days' => 5, 'mode' => 'free'])
            ->assertJsonPath('data.state.daysLeft', 45);
        $this->actingAs($owner)->getJson('/api/v1/business/personal-link')
            ->assertJsonPath('data.daysLeft', 45)
            ->assertJsonCount(2, 'data.history');

        $this->actingAs($admin)->getJson('/api/v1/admin/personal-links?status=active')
            ->assertOk()->assertJsonPath('data.0.daysLeft', 45);
    }

    public function test_an_admin_payment_link_adds_days_only_once_it_is_paid(): void
    {
        [$owner, $business] = $this->owner();
        $admin = User::factory()->admin()->create();
        Http::fake([
            'api.razorpay.com/v1/payment_links/plink_ABC' => Http::sequence()
                ->push(['id' => 'plink_ABC', 'status' => 'created', 'amount' => 39900])
                ->push(['id' => 'plink_ABC', 'status' => 'paid', 'amount' => 39900, 'amount_paid' => 39900,
                    'payments' => [['payment_id' => 'pay_LINK1', 'status' => 'captured', 'method' => 'upi']]]),
            'api.razorpay.com/v1/payment_links' => Http::response(['id' => 'plink_ABC', 'short_url' => 'https://rzp.io/i/abc', 'status' => 'created']),
        ]);

        $grantId = $this->actingAs($admin)->postJson("/api/v1/admin/personal-links/{$business->uuid}/grant", ['pack' => 'quarter', 'mode' => 'link'])
            ->assertOk()
            ->assertJsonPath('data.grant.status', 'pending')
            ->assertJsonPath('data.grant.linkUrl', 'https://rzp.io/i/abc')
            ->assertJsonPath('data.grant.amount', 399)
            ->assertJsonPath('data.state.active', false)
            ->json('data.grant.id');

        $this->postJson("/api/v1/admin/personal-links/grants/{$grantId}/sync")->assertJsonPath('data.state.active', false);
        $this->postJson("/api/v1/admin/personal-links/grants/{$grantId}/sync")
            ->assertJsonPath('data.state.active', true)
            ->assertJsonPath('data.state.daysLeft', 90)
            ->assertJsonPath('data.state.history.0.status', 'active');

        $payment = Payment::where('gateway_order_id', 'plink_ABC')->first();
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame('pay_LINK1', $payment->gateway_payment_id);
    }

    public function test_the_payment_link_webhook_activates_once(): void
    {
        [, $business] = $this->owner();
        $admin = User::factory()->admin()->create();
        Http::fake(['api.razorpay.com/v1/payment_links' => Http::response(['id' => 'plink_WH', 'short_url' => 'https://rzp.io/i/wh', 'status' => 'created'])]);
        $this->actingAs($admin)->postJson("/api/v1/admin/personal-links/{$business->uuid}/grant", ['pack' => 'year', 'mode' => 'link'])->assertOk();

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_WH', 'status' => 'paid']],
            'payment' => ['entity' => ['id' => 'pay_WH', 'order_id' => 'order_WH', 'amount' => 149900, 'status' => 'captured', 'method' => 'card']],
        ]]);
        $send = fn () => $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], $body);

        $send()->assertOk()->assertJsonPath('data.result', 'captured');
        $send()->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(365, (int) round(now()->diffInDays($business->refresh()->personal_link_expires_at)));
    }
}
