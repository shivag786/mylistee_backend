<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\ServiceSettingService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paying for customer orders online. The gateway is faked; what is under test is
 * everything that decides where money and orders go: the split, who can pay how,
 * whether an unpaid order ever reaches the shop, and what a capture, a failure
 * or an abandoned window does to the order and its coins.
 */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_ID = 'rzp_test_key';

    private const KEY_SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'whsec_test';

    private const ORDER_ID = 'order_ORDERPAY12345';

    private const PAYMENT_ID = 'pay_ORDERPAY12345';

    private function connectGateway(): void
    {
        config([
            'razorpay.key_id' => self::KEY_ID,
            'razorpay.key_secret' => self::KEY_SECRET,
            'razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(float $price = 200): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => $price]);

        return [$owner, $business, $product];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function as(User $user): static
    {
        // The auth manager memoises the resolved user; forget it so switching
        // users mid-test really switches.
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @param  array<string, mixed>  $settings */
    private function configure(Business $business, array $settings): void
    {
        $service = app(ServiceSettingService::class);
        $service->for($business)->update($settings);
    }

    private function placeOrder(User $customer, Business $business, Product $product, ?string $choice): \Illuminate\Testing\TestResponse
    {
        return $this->as($customer)->postJson('/api/v1/orders', array_filter([
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'paymentChoice' => $choice,
        ]));
    }

    private function signature(string $orderId = self::ORDER_ID, string $paymentId = self::PAYMENT_ID): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET);
    }

    /** @param  array<string, mixed>  $paymentOverrides */
    private function fakeGateway(int $amountPaise, array $paymentOverrides = [], bool $refundFails = false): void
    {
        Http::fake([
            // Registered first: payments/* below would otherwise match .../refund.
            'api.razorpay.com/v1/payments/*/refund' => $refundFails
                ? Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Refund failed']], 400)
                : Http::response(['id' => 'rfnd_ORDER1', 'payment_id' => self::PAYMENT_ID, 'amount' => $amountPaise, 'status' => 'processed']),
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

    // ---------------------------------------------------------------- the split

    public function test_cash_goes_straight_to_the_shop_as_before(): void
    {
        [$owner, $business, $product] = $this->shop();

        $this->placeOrder($this->customer(), $business, $product, 'cod')
            ->assertCreated()
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.onlineAmount', 0)
            ->assertJsonPath('data.amountDue', 200);

        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'order_placed']);
    }

    public function test_a_full_online_order_waits_for_its_money(): void
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        app(SettingService::class)->set(['razorpayFeePercent' => 2]);

        $this->placeOrder($this->customer(), $business, $product, 'online')
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.onlineAmount', 200)
            // 2% on the online share, on top -- never out of the shop's price.
            ->assertJsonPath('data.convenienceFee', 4)
            ->assertJsonPath('data.total', 200);

        // Not an order for the shop yet: nobody rings them for an unpaid one.
        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id, 'type' => 'order_placed']);
    }

    public function test_a_partial_shop_takes_its_share_up_front_rounded_up(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop(199);
        $this->configure($business, ['payment_mode' => 'partial', 'partial_percent' => 30]);

        // 30% of 199 is 59.70 -- a deposit shows as a whole rupee, rounded up so
        // it never dips under the share the shop asked for.
        $this->placeOrder($this->customer(), $business, $product, 'online')
            ->assertCreated()
            ->assertJsonPath('data.onlineAmount', 60);
    }

    // ------------------------------------------------------ who can pay how

    public function test_a_shop_that_switched_cash_off_refuses_it(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $this->configure($business, ['cod_enabled' => false]);

        $this->placeOrder($this->customer(), $business, $product, 'cod')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paymentChoice');
    }

    public function test_without_a_gateway_cash_is_offered_even_if_the_shop_switched_it_off(): void
    {
        // Otherwise a shop that turned cash off would silently stop taking any
        // orders the moment Razorpay was not set up.
        [, $business, $product] = $this->shop();
        $this->configure($business, ['cod_enabled' => false]);

        $this->placeOrder($this->customer(), $business, $product, 'cod')
            ->assertCreated()
            ->assertJsonPath('data.status', 'placed');
    }

    public function test_online_is_refused_without_a_gateway(): void
    {
        [, $business, $product] = $this->shop();

        $this->placeOrder($this->customer(), $business, $product, 'online')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paymentChoice');
    }

    public function test_an_older_client_that_sends_no_choice_still_pays_cash(): void
    {
        [, $business, $product] = $this->shop();

        $this->placeOrder($this->customer(), $business, $product, null)
            ->assertCreated()
            ->assertJsonPath('data.status', 'placed');
    }

    // ---------------------------------------------------- the shop never sees it

    public function test_an_unpaid_order_reaches_the_shop_through_no_filter(): void
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        $this->placeOrder($this->customer(), $business, $product, 'online')->assertCreated();

        foreach (['', '?status=awaiting_payment', '?since=2000-01-01'] as $query) {
            $this->as($owner)->getJson('/api/v1/business/orders'.$query)
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    // --------------------------------------------------------- paying it

    public function test_a_verified_capture_releases_the_order_to_the_shop(): void
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');

        // 200 + 2% fee = 204.00
        $this->fakeGateway(20400);

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")
            ->assertOk()
            ->assertJsonPath('data.amount', 20400)
            ->assertJsonPath('data.keyId', self::KEY_ID);

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/verify", [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->signature(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.paidOnline', true)
            ->assertJsonPath('data.amountDue', 0);

        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'order_placed']);
        $this->as($owner)->getJson('/api/v1/business/orders')->assertJsonCount(1, 'data');
    }

    public function test_a_forged_signature_is_refused_and_nothing_is_released(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/verify", [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => str_repeat('0', 64),
        ])->assertUnprocessable();

        $this->assertSame(OrderStatus::AwaitingPayment, Order::where('uuid', $uuid)->first()->status);
    }

    public function test_a_payment_for_less_than_the_order_is_refused(): void
    {
        // A genuine signature on a cheaper payment must not settle a dearer order.
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400, ['amount' => 100]);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/verify", [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->signature(),
        ])->assertUnprocessable();

        $this->assertSame(OrderStatus::AwaitingPayment, Order::where('uuid', $uuid)->first()->status);
    }

    public function test_one_customer_cannot_pay_off_anothers_order(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $uuid = $this->placeOrder($this->customer(), $business, $product, 'online')->json('data.id');

        $this->as($this->customer())->postJson("/api/v1/orders/{$uuid}/payment")->assertNotFound();
    }

    // ------------------------------------------------------- walking away

    public function test_closing_the_window_withdraws_the_order_quietly(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // They closed a window; they did not cancel an order. No notification.
        $this->assertDatabaseMissing('notifications', ['user_id' => $customer->id, 'title' => 'Order cancelled']);
    }

    public function test_a_captured_order_is_never_released(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/verify", [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->signature(),
        ])->assertOk();

        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'placed');
    }

    public function test_the_sweep_withdraws_orders_left_unpaid(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $uuid = $this->placeOrder($this->customer(), $business, $product, 'online')->json('data.id');
        Order::where('uuid', $uuid)->update(['created_at' => now()->subHour()]);

        $this->artisan('orders:release-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, Order::where('uuid', $uuid)->first()->status);
    }

    public function test_the_sweep_leaves_a_fresh_checkout_alone(): void
    {
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $uuid = $this->placeOrder($this->customer(), $business, $product, 'online')->json('data.id');

        $this->artisan('orders:release-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::AwaitingPayment, Order::where('uuid', $uuid)->first()->status);
    }

    // ------------------------------------------------------------ webhook

    private function webhook(string $event, int $amountPaise): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'event' => $event,
            'payload' => ['payment' => ['entity' => [
                'id' => self::PAYMENT_ID,
                'order_id' => self::ORDER_ID,
                'amount' => $amountPaise,
                'status' => 'captured',
                'method' => 'upi',
            ]]],
        ]);

        return $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }

    public function test_the_webhook_releases_an_order_whose_tab_was_closed(): void
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();

        $this->webhook('payment.captured', 20400)->assertOk()->assertJsonPath('data.result', 'captured');

        $this->assertSame(OrderStatus::Placed, Order::where('uuid', $uuid)->first()->status);
        // Routed as an order payment: no plan was touched, no subscription made.
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_late_payment_brings_a_withdrawn_order_back(): void
    {
        // UPI can approve after the modal closed and the order was released.
        // The money is real, so the order must be too.
        $this->connectGateway();
        [, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/release")->assertOk();

        $this->webhook('payment.captured', 20400)->assertOk();

        $order = Order::where('uuid', $uuid)->first();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertNotNull($order->online_paid_at);
        $this->assertSame(PaymentStatus::Captured, Payment::where('order_id', $order->id)->first()->status);
    }

    public function test_the_webhook_and_verify_do_not_double_capture(): void
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();

        $this->webhook('payment.captured', 20400)->assertOk();
        $this->webhook('payment.captured', 20400)->assertOk()->assertJsonPath('data.result', 'duplicate');

        // One order rang in, not two.
        $this->assertSame(1, \App\Models\Notification::where('user_id', $owner->id)->where('type', 'order_placed')->count());
    }

    // --------------------------------------------------------- shop cancels

    /** Place an online order and pay for it, returning [owner, customer, uuid]. */
    private function paidOrder(bool $refundFails = false): array
    {
        $this->connectGateway();
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();
        $uuid = $this->placeOrder($customer, $business, $product, 'online')->json('data.id');
        $this->fakeGateway(20400, [], $refundFails);
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment")->assertOk();
        $this->as($customer)->postJson("/api/v1/orders/{$uuid}/payment/verify", [
            'razorpayOrderId' => self::ORDER_ID,
            'razorpayPaymentId' => self::PAYMENT_ID,
            'razorpaySignature' => $this->signature(),
        ])->assertOk();

        return [$owner, $customer, $uuid];
    }

    public function test_cancelling_a_paid_order_gives_the_customer_their_money_back(): void
    {
        [$owner, , $uuid] = $this->paidOrder();

        $this->as($owner)->patchJson("/api/v1/business/orders/{$uuid}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // The whole of it, convenience fee included -- they did not cancel.
        $payment = Payment::whereHas('order', fn ($q) => $q->where('uuid', $uuid))->first();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertEquals(204.00, (float) $payment->refunded_amount);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments/'.self::PAYMENT_ID.'/refund'));
    }

    public function test_a_failed_refund_stops_the_cancel(): void
    {
        // Better the order stays than the money vanishes with it.
        [$owner, , $uuid] = $this->paidOrder(refundFails: true);

        $this->as($owner)->patchJson("/api/v1/business/orders/{$uuid}/status", ['status' => 'cancelled'])
            ->assertUnprocessable();

        $this->assertSame(OrderStatus::Placed, Order::where('uuid', $uuid)->first()->status);
    }

    public function test_a_cash_order_cancels_without_touching_the_gateway(): void
    {
        [$owner, $business, $product] = $this->shop();
        Http::fake();
        $uuid = $this->placeOrder($this->customer(), $business, $product, 'cod')->json('data.id');

        $this->as($owner)->patchJson("/api/v1/business/orders/{$uuid}/status", ['status' => 'cancelled'])
            ->assertOk();

        Http::assertNothingSent();
    }
}
