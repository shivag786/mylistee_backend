<?php

namespace Tests\Feature\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Razorpay checkout for business plans. The gateway itself is faked — what is
 * under test is the part that decides whether money moved: signature
 * verification, the server-side amount check, and idempotency between the
 * browser callback and the webhook.
 */
class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_ID = 'rzp_test_key';

    private const KEY_SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'whsec_test';

    private const ORDER_ID = 'order_TEST123456789';

    private const PAYMENT_ID = 'pay_TEST123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        config([
            'razorpay.key_id' => self::KEY_ID,
            'razorpay.key_secret' => self::KEY_SECRET,
            'razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /** @return array{0: User, 1: Business} */
    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    /**
     * Authenticate the next request as this user.
     *
     * Laravel keeps one application instance for the whole test, and the auth
     * manager memoizes the resolved user per guard — so swapping the bearer token
     * alone would keep the *previous* user signed in. Forgetting the guards is
     * what makes "another owner tries to claim this payment" actually test that.
     */
    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function proPlan(): Plan
    {
        return Plan::where('key', 'pro')->firstOrFail();
    }

    /** The signature Razorpay Checkout would hand the browser. */
    private function checkoutSignature(string $orderId = self::ORDER_ID, string $paymentId = self::PAYMENT_ID): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET);
    }

    /**
     * Stub the gateway for a whole test.
     *
     * Call this exactly once per test: Http::fake() *merges* stubs, so a second
     * call would never override the first — the payment stub has to carry its
     * overrides from the start.
     *
     * @param  array<string, mixed>  $paymentOverrides  What the gateway reports for
     *                                                  the payment, e.g. a smaller
     *                                                  amount or a failed status.
     */
    private function fakeGateway(int $amountPaise, array $paymentOverrides = []): void
    {
        Http::fake([
            // Registered first: the payments/* wildcard below would otherwise
            // match .../payments/{id}/refund and stub the wrong response.
            'api.razorpay.com/v1/payments/*/refund' => Http::response([
                'id' => 'rfnd_TEST123',
                'payment_id' => self::PAYMENT_ID,
                'amount' => $amountPaise,
                'status' => 'processed',
            ]),
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => self::ORDER_ID,
                'amount' => $amountPaise,
                'currency' => 'INR',
                'status' => 'created',
            ]),
            'api.razorpay.com/v1/payments/*' => Http::response(array_merge([
                'id' => self::PAYMENT_ID,
                'order_id' => self::ORDER_ID,
                'amount' => $amountPaise,
                'currency' => 'INR',
                'status' => 'captured',
                'method' => 'upi',
            ], $paymentOverrides)),
        ]);
    }

    // ------------------------------------------------------------- checkout

    public function test_checkout_creates_a_razorpay_order_and_a_pending_payment(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $plan = $this->proPlan();
        $this->fakeGateway((int) ($plan->price * 100));

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro'])
            ->assertOk()
            ->assertJsonPath('data.orderId', self::ORDER_ID)
            ->assertJsonPath('data.keyId', self::KEY_ID)
            ->assertJsonPath('data.amount', (int) ($plan->price * 100));

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertSame(self::ORDER_ID, $payment->gateway_order_id);
        // The plan must NOT be live yet — nothing has been paid.
        $this->assertSame(0, $payment->business->subscriptions()->count());
    }

    public function test_checkout_never_leaks_the_key_secret(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $response = $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro'])
            ->assertOk();

        $this->assertStringNotContainsString(self::KEY_SECRET, $response->getContent());
    }

    public function test_checkout_rejects_the_free_plan(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'free'])
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    public function test_checkout_is_unavailable_when_the_gateway_is_not_configured(): void
    {
        config(['razorpay.key_id' => null, 'razorpay.key_secret' => null]);
        [$owner] = $this->ownerWithBusiness();

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro'])
            ->assertStatus(503);
    }

    public function test_a_paid_plan_cannot_be_switched_to_without_paying(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription', ['planKey' => 'pro'])
            ->assertStatus(422);

        $this->assertSame(0, $business->subscriptions()->count());
    }

    public function test_downgrading_to_free_still_needs_no_payment(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription', ['planKey' => 'free'])
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'free');

        $this->assertSame(0, $business->invoices()->count());
    }

    // --------------------------------------------------------------- verify

    public function test_a_verified_payment_activates_the_plan_and_bills_an_invoice(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $plan = $this->proPlan();
        $this->fakeGateway((int) ($plan->price * 100));

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/verify', [
                'razorpayOrderId' => self::ORDER_ID,
                'razorpayPaymentId' => self::PAYMENT_ID,
                'razorpaySignature' => $this->checkoutSignature(),
            ])
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'pro');

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame(self::PAYMENT_ID, $payment->gateway_payment_id);

        $invoice = $business->invoices()->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame((float) $plan->price, (float) $invoice->amount);
        // Real money — so it must not be tagged as a simulated upgrade.
        $this->assertArrayNotHasKey('simulated', $invoice->meta);
        $this->assertSame(self::PAYMENT_ID, $invoice->meta['gatewayPaymentId']);
        $this->assertSame($invoice->id, $payment->invoice_id);
    }

    public function test_a_forged_signature_is_rejected_and_never_activates_the_plan(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/verify', [
                'razorpayOrderId' => self::ORDER_ID,
                'razorpayPaymentId' => self::PAYMENT_ID,
                'razorpaySignature' => str_repeat('0', 64),
            ])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        $this->assertSame(0, $business->subscriptions()->count());
        $this->assertSame(0, $business->invoices()->count());
    }

    public function test_a_payment_worth_less_than_its_order_is_rejected(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        // The order is for ₹1499, but the gateway reports only ₹1 was paid.
        $this->fakeGateway(149900, ['amount' => 100]);
        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/verify', [
                'razorpayOrderId' => self::ORDER_ID,
                'razorpayPaymentId' => self::PAYMENT_ID,
                'razorpaySignature' => $this->checkoutSignature(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, $business->subscriptions()->count());
    }

    public function test_an_uncaptured_payment_does_not_activate_the_plan(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->fakeGateway(149900, ['status' => 'failed', 'error_description' => 'Card declined']);
        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/verify', [
                'razorpayOrderId' => self::ORDER_ID,
                'razorpayPaymentId' => self::PAYMENT_ID,
                'razorpaySignature' => $this->checkoutSignature(),
            ])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        $this->assertSame(0, $business->subscriptions()->count());
    }

    public function test_verifying_twice_does_not_bill_twice(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $payload = [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->checkoutSignature(),
        ];

        $this->asUser($owner)->postJson('/api/v1/business/subscription/verify', $payload)->assertOk();
        $this->asUser($owner)->postJson('/api/v1/business/subscription/verify', $payload)->assertOk();

        $this->assertSame(1, $business->invoices()->count());
        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_a_payment_cannot_be_verified_against_another_business(): void
    {
        [$owner, $ownerBiz] = $this->ownerWithBusiness();
        [$attacker, $attackerBiz] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($attacker)
            ->postJson('/api/v1/business/subscription/verify', [
                'razorpayOrderId' => self::ORDER_ID,
                'razorpayPaymentId' => self::PAYMENT_ID,
                'razorpaySignature' => $this->checkoutSignature(),
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------- webhook

    public function test_the_webhook_rejects_an_invalid_signature(): void
    {
        $this->call(
            'POST',
            '/api/v1/webhooks/razorpay',
            server: ['HTTP_X_RAZORPAY_SIGNATURE' => 'nope', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['event' => 'payment.captured']),
        )->assertStatus(401);
    }

    public function test_the_webhook_activates_a_plan_the_browser_never_confirmed(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $plan = $this->proPlan();
        $amountPaise = (int) ($plan->price * 100);
        $this->fakeGateway($amountPaise);

        // The owner opens Checkout and pays, but closes the tab before verify().
        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->postWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => self::PAYMENT_ID,
                'order_id' => self::ORDER_ID,
                'amount' => $amountPaise,
                'status' => 'captured',
                'method' => 'card',
            ]]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Captured, Payment::sole()->status);
        $this->assertSame('pro', $business->fresh()->currentPlan()->key);
        $this->assertSame(1, $business->invoices()->count());
    }

    public function test_the_webhook_will_not_activate_a_plan_for_the_wrong_amount(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)
            ->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->postWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => self::PAYMENT_ID,
                'order_id' => self::ORDER_ID,
                'amount' => 100,
                'status' => 'captured',
            ]]],
        ])->assertOk()->assertJsonPath('data.result', 'mismatch');

        $this->assertSame(0, $business->subscriptions()->count());
    }

    public function test_the_webhook_does_not_re_bill_an_already_verified_payment(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $amountPaise = 149900;
        $this->fakeGateway($amountPaise);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);
        $this->asUser($owner)->postJson('/api/v1/business/subscription/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->checkoutSignature(),
        ])->assertOk();

        $this->postWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => self::PAYMENT_ID,
                'order_id' => self::ORDER_ID,
                'amount' => $amountPaise,
                'status' => 'captured',
            ]]],
        ])->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(1, $business->invoices()->count());
    }

    // --------------------------------------------------------- admin refunds

    public function test_an_admin_can_refund_a_captured_payment_and_the_plan_ends(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);
        $this->asUser($owner)->postJson('/api/v1/business/subscription/verify', [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->checkoutSignature(),
        ])->assertOk();

        $payment = Payment::sole();
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)
            ->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", ['reason' => 'Duplicate charge'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        Http::assertSent(
            fn ($request) => str_contains($request->url(), 'payments/'.self::PAYMENT_ID.'/refund')
                && $request['amount'] === 149900,
        );

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Refunded, $business->invoices()->sole()->status);
        // Money went back, so the plan goes with it — not at period end.
        $this->assertSame('free', $business->fresh()->currentPlan()->key);
    }

    public function test_a_payment_that_was_never_captured_cannot_be_refunded(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $admin = User::factory()->admin()->create();

        $this->asUser($admin)
            ->postJson('/api/v1/admin/payments/'.Payment::sole()->uuid.'/refund')
            ->assertStatus(422);
    }

    public function test_an_owner_cannot_issue_refunds(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $this->fakeGateway(149900);

        $this->asUser($owner)->postJson('/api/v1/business/subscription/checkout', ['planKey' => 'pro']);

        $this->asUser($owner)
            ->postJson('/api/v1/admin/payments/'.Payment::sole()->uuid.'/refund')
            ->assertForbidden();
    }

    /** @param  array<string, mixed>  $payload */
    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/api/v1/webhooks/razorpay',
            server: [
                'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body,
        );
    }
}
