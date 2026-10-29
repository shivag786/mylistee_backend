<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Rewards & coupons": bill discount, come-back + loyalty coupons, codes, and Listee's fee. */
class RewardsAndCouponsTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business, 2: Product} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business, Product::factory()->create(['business_id' => $business->id, 'selling_price' => 250])];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function setUpRewards(User $owner): void
    {
        $this->token($owner)->putJson('/api/v1/business/rewards', [
            'bill_discount' => ['type' => 'flat', 'value' => 50, 'min_bill' => 400],
            'comeback' => ['type' => 'percent', 'value' => 10, 'max_off' => 100, 'days' => 15],
            'loyalty' => ['type' => 'flat', 'value' => 100, 'every' => 3],
        ])->assertOk()->assertJsonPath('data.billDiscount.min_bill', 400);
    }

    private function order(User $customer, Business $business, Product $product, int $qty, ?string $code = null)
    {
        return $this->token($customer)->postJson('/api/v1/orders', array_filter([
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => $qty]],
            'couponCode' => $code,
        ]));
    }

    private function markPaid(User $owner, string $orderId): void
    {
        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'confirmed'])->assertOk();
        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'paid'])->assertOk();
    }

    public function test_bill_discount_applies_above_the_minimum_and_quote_nudges_below_it(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->setUpRewards($owner);

        // ₹250 — under ₹400, so a nudge rather than a discount (a guest can quote).
        $this->postJson('/api/v1/orders/quote', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.billDiscount', 0)
            ->assertJsonPath('data.acceptingOrders', true)
            ->assertJsonPath('data.billNudge.needed', 150)
            ->assertJsonPath('data.billNudge.label', '₹50 off');

        // ₹500 → ₹50 off; Listee's 2% comes out of the shop's share, not the bill.
        $this->order($this->customer(), $business, $product, 2)->assertCreated()
            ->assertJsonPath('data.billDiscount', 50)
            ->assertJsonPath('data.total', 450);
        $order = Order::latest('id')->first();
        $this->assertSame('9.00', $order->platform_fee);
        $this->assertSame('441.00', $order->vendor_earning);
    }

    public function test_a_coupon_replaces_the_bill_discount_only_when_it_saves_more(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->setUpRewards($owner);
        $customer = $this->customer();
        $small = Coupon::create(['business_id' => $business->id, 'kind' => 'code', 'code' => 'SMALL30', 'title' => '₹30 off', 'discount_type' => 'flat', 'value' => 30, 'active' => true]);
        $big = Coupon::create(['business_id' => $business->id, 'kind' => 'code', 'code' => 'BIG80', 'title' => '₹80 off', 'discount_type' => 'flat', 'value' => 80, 'active' => true]);

        // ₹500 earns ₹50 off; a ₹30 coupon would only lose the customer money.
        $this->order($customer, $business, $product, 2, $small->code)->assertUnprocessable()
            ->assertJsonValidationErrors('couponCode');

        // ₹80 beats ₹50: the coupon applies and the bill discount steps aside.
        $this->order($customer, $business, $product, 2, $big->code)->assertCreated()
            ->assertJsonPath('data.billDiscount', 0)
            ->assertJsonPath('data.couponDiscount', 80)
            ->assertJsonPath('data.total', 420);
    }

    public function test_paid_orders_earn_comeback_and_every_third_a_loyalty_coupon(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->setUpRewards($owner);
        $customer = $this->customer();

        foreach (range(1, 3) as $n) {
            $this->markPaid($owner, $this->order($customer, $business, $product, 1)->json('data.id'));
        }

        // Three come-back coupons and one loyalty coupon on the third order.
        $this->assertSame(3, Coupon::where('customer_id', $customer->id)->where('kind', 'comeback')->count());
        $loyalty = Coupon::where('customer_id', $customer->id)->where('kind', 'loyalty')->sole();

        $perks = $this->token($customer)->getJson("/api/v1/businesses/{$business->slug}")->assertOk()->json('data.perks');
        $this->assertSame(0, $perks['loyalty']['done']);
        $this->assertCount(4, $perks['coupons']);

        // A guest sees the rules, but no progress and no one's personal coupons.
        $this->app['auth']->forgetGuards();
        $guest = $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/businesses/{$business->slug}")->json('data.perks');
        $this->assertNull($guest['loyalty']['done']);
        $this->assertCount(0, $guest['coupons']);

        // The loyalty coupon takes ₹100 off and is single-use; cancelling gives it back.
        $orderId = $this->order($customer, $business, $product, 1, $loyalty->code)->assertCreated()
            ->assertJsonPath('data.couponDiscount', 100)
            ->assertJsonPath('data.couponCode', $loyalty->code)
            ->json('data.id');
        $this->order($customer, $business, $product, 1, $loyalty->code)->assertUnprocessable();

        $this->token($owner)->patchJson("/api/v1/business/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->order($customer, $business, $product, 1, $loyalty->code)->assertCreated();
    }

    public function test_loyalty_steps_reward_the_3rd_5th_and_6th_order_and_repeat(): void
    {
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();

        $this->token($owner)->putJson('/api/v1/business/rewards', [
            'bill_discount' => null,
            'comeback' => null,
            'loyalty' => ['repeat' => true, 'steps' => [
                ['at' => 5, 'type' => 'flat', 'value' => 50],
                ['at' => 3, 'type' => 'flat', 'value' => 20],
                ['at' => 6, 'type' => 'percent', 'value' => 50, 'max_off' => 150],
            ]],
        ])->assertOk()
            // Saved in order, whatever order they came in.
            ->assertJsonPath('data.loyalty.steps.0.at', 3)
            ->assertJsonPath('data.loyalty.steps.2.at', 6)
            ->assertJsonPath('data.loyalty.repeat', true);

        foreach (range(1, 9) as $n) {
            $this->markPaid($owner, $this->order($customer, $business, $product, 1)->json('data.id'));
        }

        // 3rd, 5th, 6th — then the card starts over and the 9th is the next 3rd.
        $this->assertSame(
            [3, 5, 6, 3],
            Coupon::where('customer_id', $customer->id)->where('kind', 'loyalty')->orderBy('id')->pluck('tier_at')->all(),
        );

        $card = $this->token($customer)->getJson("/api/v1/businesses/{$business->slug}")->json('data.perks.loyalty');
        $this->assertSame(3, $card['done']);
        $this->assertSame(2, $card['round']);
        $this->assertSame(5, $card['next']['at']);
        $this->assertSame(2, $card['next']['ordersLeft']);
        $this->assertTrue($card['steps'][0]['reached']);
        $this->assertFalse($card['steps'][1]['reached']);

        // Scratch cards: hidden until opened, then the code shows.
        $cards = $this->token($customer)->getJson('/api/v1/rewards/cards')->assertOk()->json('data');
        $this->assertCount(4, $cards);
        $this->assertNull($cards[0]['code']);
        $this->token($customer)->postJson("/api/v1/rewards/cards/{$cards[0]['id']}/reveal")->assertOk()
            ->assertJsonPath('data.revealed', true)
            ->assertJsonPath('data.label', '₹20 off');

        // Nobody else can scratch them.
        $this->token($this->customer())->postJson("/api/v1/rewards/cards/{$cards[1]['id']}/reveal")->assertNotFound();

        // The owner sees who comes back.
        $this->token($owner)->getJson('/api/v1/business/rewards/customers')->assertOk()
            ->assertJsonPath('data.summary.repeat', 1)
            ->assertJsonPath('data.customers.0.orders', 9)
            ->assertJsonPath('data.customers.0.rewardsEarned', 4)
            ->assertJsonPath('data.customers.0.next.at', 5);
    }

    public function test_one_time_loyalty_steps_stop_after_the_last(): void
    {
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();

        $this->token($owner)->putJson('/api/v1/business/rewards', [
            'bill_discount' => null,
            'comeback' => null,
            'loyalty' => ['repeat' => false, 'steps' => [
                ['at' => 1, 'type' => 'flat', 'value' => 10],
                ['at' => 2, 'type' => 'flat', 'value' => 30],
            ]],
        ])->assertOk();

        foreach (range(1, 4) as $n) {
            $this->markPaid($owner, $this->order($customer, $business, $product, 1)->json('data.id'));
        }

        $this->assertSame(2, Coupon::where('customer_id', $customer->id)->where('kind', 'loyalty')->count());
        $card = $this->token($customer)->getJson("/api/v1/businesses/{$business->slug}")->json('data.perks.loyalty');
        $this->assertTrue($card['finished']);
        $this->assertNull($card['next']);
    }

    public function test_loyalty_steps_must_each_have_their_own_order_number(): void
    {
        [$owner] = $this->shop();

        $this->token($owner)->putJson('/api/v1/business/rewards', [
            'bill_discount' => null,
            'comeback' => null,
            'loyalty' => ['steps' => [
                ['at' => 3, 'type' => 'flat', 'value' => 20],
                ['at' => 3, 'type' => 'flat', 'value' => 50],
            ]],
        ])->assertUnprocessable();
    }

    public function test_owner_coupon_codes_check_minimum_bill_and_per_customer_use(): void
    {
        [$owner, $business, $product] = $this->shop();
        $this->token($owner)->postJson('/api/v1/business/coupons', [
            'code' => 'welcome20', 'discountType' => 'percent', 'value' => 20, 'maxDiscount' => 60, 'minBill' => 300,
        ])->assertCreated()->assertJsonPath('data.code', 'WELCOME20')->assertJsonPath('data.label', '20% off up to ₹60');

        $customer = $this->customer();
        $this->order($customer, $business, $product, 1, 'WELCOME20')->assertUnprocessable()
            ->assertJsonFragment(['Add ₹50 more to use WELCOME20.']);
        // 20% of ₹500 is ₹100, capped at ₹60.
        $this->order($customer, $business, $product, 2, 'welcome20')->assertCreated()->assertJsonPath('data.couponDiscount', 60);
        $this->order($customer, $business, $product, 2, 'WELCOME20')->assertUnprocessable()
            ->assertJsonFragment(['You’ve already used this coupon.']);

        // A bad code in a quote is reported, not thrown.
        $this->postJson('/api/v1/orders/quote', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
            'couponCode' => 'NOPE',
        ])->assertOk()->assertJsonPath('data.couponError', 'That code isn’t valid at this shop.');
    }

    public function test_online_orders_carry_the_gateway_share_too(): void
    {
        app(SettingService::class)->set(['platformFeePercent' => 2, 'razorpayFeePercent' => 2]);
        $fees = app(\App\Services\PlatformFeeService::class)->split(500, 500);

        $this->assertSame(['platform' => 10.0, 'gateway' => 10.0, 'vendor' => 480.0], $fees);
    }

    public function test_a_loyalty_step_can_ask_for_a_minimum_bill(): void
    {
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();

        $this->token($owner)->putJson('/api/v1/business/rewards', [
            'bill_discount' => null,
            'comeback' => null,
            'loyalty' => ['repeat' => false, 'steps' => [
                ['at' => 1, 'type' => 'flat', 'value' => 40, 'min_bill' => 300],
            ]],
        ])->assertOk()->assertJsonPath('data.loyalty.steps.0.min_bill', 300);

        $this->markPaid($owner, $this->order($customer, $business, $product, 1)->json('data.id'));

        $coupon = Coupon::where('customer_id', $customer->id)->where('kind', 'loyalty')->sole();
        $this->assertSame(300.0, (float) $coupon->min_bill);
        // ₹250 is under the ₹300 the step asks for.
        $this->order($customer, $business, $product, 1, $coupon->code)->assertUnprocessable();
        $this->order($customer, $business, $product, 2, $coupon->code)->assertCreated()->assertJsonPath('data.couponDiscount', 40);

        $card = $this->token($customer)->getJson("/api/v1/businesses/{$business->slug}")->json('data.perks.loyalty');
        $this->assertSame('₹40 off on ₹300+', $card['steps'][0]['label']);
    }

    public function test_the_admin_switches_rewards_and_coins_off_separately(): void
    {
        [$owner, $business, $product] = $this->shop();
        $customer = $this->customer();
        $this->setUpRewards($owner);

        \App\Models\FeatureFlag::updateOrCreate(['key' => 'owner_rewards'], ['name' => 'Rewards', 'enabled' => false]);
        \App\Models\FeatureFlag::updateOrCreate(['key' => 'owner_loyalty'], ['name' => 'Coins', 'enabled' => true]);

        // Rewards off: no bill discount, no perks on the shop page.
        $this->order($customer, $business, $product, 2)->assertCreated()->assertJsonPath('data.billDiscount', 0);
        $perks = $this->token($customer)->getJson("/api/v1/businesses/{$business->slug}")->json('data.perks');
        $this->assertNull($perks['billDiscount']);
        $this->assertSame([], $perks['coupons']);
        $this->getJson('/api/v1/config')->assertJsonPath('data.rewardsEnabled', false)->assertJsonPath('data.coins.enabled', true);

        // Coins off is its own switch.
        \App\Models\FeatureFlag::where('key', 'owner_loyalty')->update(['enabled' => false]);
        $this->assertFalse(app(\App\Services\LoyaltyService::class)->isEnabled());
        $this->getJson('/api/v1/config')->assertJsonPath('data.coins.enabled', false);
    }
}
