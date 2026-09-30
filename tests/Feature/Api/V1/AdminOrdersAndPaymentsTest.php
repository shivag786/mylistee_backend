<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin's view of orders and the money behind them. What matters here is
 * that support can find an order by what the customer has in hand -- a token, a
 * number, a Razorpay id -- and that refunding one leaves the order in a state
 * the shop will not fulfil by mistake.
 */
class AdminOrdersAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_ID = 'pay_ADMINREF12345';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'razorpay.key_id' => 'rzp_test_key',
            'razorpay.key_secret' => 'rzp_test_secret',
            'razorpay.webhook_secret' => 'whsec_test',
        ]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * An order paid online, with its captured payment -- the state a refund starts from.
     *
     * @param  array<string, mixed>  $orderOverrides
     */
    private function paidOrder(array $orderOverrides = []): Order
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Chai Point']);
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
            'name' => 'Asha Rao',
            'phone' => '9876543210',
        ]);

        $order = Order::create(array_merge([
            'token' => '4821',
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Placed,
            'service_type' => 'pickup',
            'subtotal' => 200,
            'total' => 200,
            'payment_choice' => 'online',
            'online_amount' => 200,
            'convenience_fee' => 4,
            'online_paid_at' => now(),
            'placed_at' => now(),
        ], $orderOverrides));

        Payment::create([
            'business_id' => $business->id,
            'order_id' => $order->id,
            'created_by' => $customer->id,
            // Unique per order; the first (token 4821) keeps the ids the
            // search and refund tests look for.
            'gateway_order_id' => $order->token === '4821' ? 'order_ADMINREF12345' : 'order_T'.$order->token,
            'gateway_payment_id' => $order->token === '4821' ? self::PAYMENT_ID : 'pay_T'.$order->token,
            'status' => PaymentStatus::Captured,
            'amount' => 204,
            'amount_paise' => 20400,
            'currency' => 'INR',
            'method' => 'upi',
            'paid_at' => now(),
        ]);

        return $order;
    }

    private function fakeRefund(): void
    {
        Http::fake([
            'api.razorpay.com/v1/payments/*/refund' => Http::response([
                'id' => 'rfnd_ADMIN1',
                'payment_id' => self::PAYMENT_ID,
                'amount' => 20400,
                'status' => 'processed',
            ]),
        ]);
    }

    // ------------------------------------------------------------ orders

    public function test_the_admin_sees_orders_from_every_shop_with_their_payment(): void
    {
        $this->paidOrder();

        $this->as($this->admin())->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.token', '4821')
            ->assertJsonPath('data.0.businessName', 'Chai Point')
            ->assertJsonPath('data.0.customerName', 'Asha Rao')
            ->assertJsonPath('data.0.paymentType', 'online')
            ->assertJsonPath('data.0.payment.gatewayPaymentId', self::PAYMENT_ID)
            ->assertJsonPath('data.0.payment.status', 'captured');
    }

    public function test_orders_still_waiting_on_payment_are_visible_to_support(): void
    {
        // Hidden from the shop, but exactly what "I paid and nothing came" is about.
        $this->paidOrder(['status' => OrderStatus::AwaitingPayment, 'online_paid_at' => null]);

        $this->as($this->admin())->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_payment')
            ->assertJsonPath('meta.awaitingPayment', 1);
    }

    public function test_support_finds_an_order_by_what_the_customer_has_in_hand(): void
    {
        $this->paidOrder();
        $admin = $this->admin();

        foreach (['4821', '98765', self::PAYMENT_ID, 'order_ADMINREF12345', 'Asha'] as $search) {
            $this->as($admin)->getJson('/api/v1/admin/orders?search='.urlencode($search))
                ->assertOk()
                ->assertJsonPath('data.0.token', '4821');
        }
    }

    public function test_orders_filter_by_how_they_were_paid(): void
    {
        $this->paidOrder(); // online, all of it
        $this->paidOrder(['token' => '1111', 'online_amount' => 60]); // an advance
        $this->paidOrder(['token' => '2222', 'payment_choice' => 'cod', 'online_amount' => 0, 'online_paid_at' => null]);
        $admin = $this->admin();

        foreach (['online' => '4821', 'partial' => '1111', 'cod' => '2222'] as $type => $token) {
            $this->as($admin)->getJson("/api/v1/admin/orders?payment={$type}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.token', $token)
                ->assertJsonPath('data.0.paymentType', $type);
        }
    }

    public function test_only_an_admin_sees_every_order(): void
    {
        $this->paidOrder();
        $owner = User::factory()->businessOwner()->create();

        $this->as($owner)->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    // ---------------------------------------------------------- payments

    public function test_a_payment_row_says_which_order_it_paid_for(): void
    {
        $this->paidOrder();

        $this->as($this->admin())->getJson('/api/v1/admin/payments?kind=order')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'order')
            ->assertJsonPath('data.0.orderToken', '4821')
            ->assertJsonPath('data.0.customerName', 'Asha Rao');
    }

    public function test_shop_money_is_kept_apart_from_platform_revenue(): void
    {
        // Order payments are owed to shops. Counting them as captured revenue
        // made the platform look bigger than it is.
        $this->seed(PlanSeeder::class);
        $this->paidOrder();
        $business = Business::first();
        Payment::create([
            'business_id' => $business->id,
            'plan_id' => Plan::where('key', 'pro')->value('id'),
            'gateway_order_id' => 'order_PLAN1',
            'gateway_payment_id' => 'pay_PLAN1',
            'status' => PaymentStatus::Captured,
            'amount' => 499,
            'amount_paise' => 49900,
            'currency' => 'INR',
        ]);

        $this->as($this->admin())->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('meta.capturedTotal', 499)
            ->assertJsonPath('meta.orderCapturedTotal', 204);
    }

    // ----------------------------------------------------------- refunds

    public function test_a_full_refund_withdraws_an_order_still_in_play(): void
    {
        $order = $this->paidOrder();
        $payment = Payment::where('order_id', $order->id)->first();
        $this->fakeRefund();

        $this->as($this->admin())->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", ['reason' => 'Shop closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        // Otherwise the customer has their money back and the shop still sees a
        // paid order to fulfil.
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $order->customer_id, 'title' => 'Order refunded']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_refund_on_a_completed_order_is_goodwill_and_the_order_stands(): void
    {
        $order = $this->paidOrder(['status' => OrderStatus::Completed, 'completed_at' => now()]);
        $payment = Payment::where('order_id', $order->id)->first();
        $this->fakeRefund();

        $this->as($this->admin())->postJson("/api/v1/admin/payments/{$payment->uuid}/refund")->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_a_partial_refund_leaves_the_order_alone(): void
    {
        $order = $this->paidOrder();
        $payment = Payment::where('order_id', $order->id)->first();
        $this->fakeRefund();

        $this->as($this->admin())->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", ['amount' => 50])
            ->assertOk()
            ->assertJsonPath('data.status', 'captured');

        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
    }

    public function test_a_refund_made_in_the_razorpay_dashboard_withdraws_the_order_too(): void
    {
        $order = $this->paidOrder();

        $body = json_encode([
            'event' => 'refund.processed',
            'payload' => ['refund' => ['entity' => [
                'id' => 'rfnd_DASH1',
                'payment_id' => self::PAYMENT_ID,
                'amount' => 20400,
                'status' => 'processed',
            ]]],
        ]);

        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], $body)->assertOk()->assertJsonPath('data.result', 'refunded');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }
}
