<?php

namespace Tests\Feature;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\Reward;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoyaltyService;
use App\Services\RedemptionService;
use App\Services\ReviewService;
use App\Services\VisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    public function test_award_credits_the_configured_amount_and_snapshots_balance(): void
    {
        config()->set('loyalty.earn.spin', 10);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $txn = $this->loyalty()->award($user, CoinSource::Spin, $business);

        $this->assertNotNull($txn);
        $this->assertSame(10, $txn->amount);
        $this->assertSame(10, $txn->balance_after);
        $this->assertSame(10, $this->loyalty()->balanceFor($user));
        $this->assertSame(10, $user->coinBalance());
    }

    public function test_balance_is_the_running_sum_of_the_ledger(): void
    {
        config()->set('loyalty.earn.spin', 10);
        config()->set('loyalty.earn.review', 20);
        $user = User::factory()->create();

        $this->loyalty()->award($user, CoinSource::Spin);
        $second = $this->loyalty()->award($user, CoinSource::Review);

        $this->assertSame(30, $this->loyalty()->balanceFor($user));
        $this->assertSame(30, $second->balance_after);
    }

    public function test_per_business_balance_isolates_by_business(): void
    {
        config()->set('loyalty.earn.spin', 10);
        $user = User::factory()->create();
        $shopA = Business::factory()->create();
        $shopB = Business::factory()->create();

        $this->loyalty()->award($user, CoinSource::Spin, $shopA);
        $this->loyalty()->award($user, CoinSource::Spin, $shopA);
        $this->loyalty()->award($user, CoinSource::Spin, $shopB);

        $this->assertSame(20, $this->loyalty()->balanceForBusiness($user, $shopA));
        $this->assertSame(10, $this->loyalty()->balanceForBusiness($user, $shopB));
        $this->assertSame(30, $this->loyalty()->balanceFor($user));
    }

    public function test_award_is_a_noop_when_loyalty_is_disabled(): void
    {
        config()->set('loyalty.enabled', false);
        $user = User::factory()->create();

        $this->assertNull($this->loyalty()->award($user, CoinSource::Spin));
        $this->assertSame(0, $this->loyalty()->balanceFor($user));
    }

    public function test_award_is_a_noop_when_the_rate_is_zero(): void
    {
        config()->set('loyalty.earn.spin', 0);
        $user = User::factory()->create();

        $this->assertNull($this->loyalty()->award($user, CoinSource::Spin));
        $this->assertSame(0, $this->loyalty()->balanceFor($user));
    }

    public function test_monthly_budget_cap_stops_over_minting(): void
    {
        config()->set('loyalty.earn.spin', 30);
        config()->set('loyalty.monthly_budget_cap', 50);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->assertNotNull($this->loyalty()->award($user, CoinSource::Spin, $business)); // 30
        // Next 30 would total 60 > 50 → blocked.
        $this->assertNull($this->loyalty()->award($user, CoinSource::Spin, $business));
        $this->assertSame(30, $this->loyalty()->balanceFor($user));
    }

    public function test_explicit_amount_overrides_the_config_rate(): void
    {
        $user = User::factory()->create();

        $txn = $this->loyalty()->award($user, CoinSource::AdminAdjust, null, ['amount' => 5]);

        $this->assertNotNull($txn);
        $this->assertSame(5, $txn->amount);
    }

    public function test_award_once_is_idempotent(): void
    {
        config()->set('loyalty.earn.review', 20);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->assertNotNull($this->loyalty()->awardOnce($user, CoinSource::Review, $business));
        $this->assertNull($this->loyalty()->awardOnce($user, CoinSource::Review, $business));
        $this->assertSame(20, $this->loyalty()->balanceFor($user));
    }

    public function test_award_once_per_day_allows_one_per_day(): void
    {
        config()->set('loyalty.earn.checkin', 5);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->assertNotNull($this->loyalty()->awardOncePerDay($user, CoinSource::Checkin, $business));
        $this->assertNull($this->loyalty()->awardOncePerDay($user, CoinSource::Checkin, $business));
        $this->assertSame(5, $this->loyalty()->balanceFor($user));
    }

    public function test_first_customer_visit_earns_a_first_scan_bonus(): void
    {
        config()->set('loyalty.earn.first_scan', 25);
        config()->set('loyalty.earn.checkin', 5);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        app(VisitService::class)->record($business, $user, Request::create('/', 'GET'));

        $this->assertTrue($this->loyalty()->hasEarned($user, CoinSource::FirstScan, $business));
        $this->assertFalse($this->loyalty()->hasEarned($user, CoinSource::Checkin, $business));
        $this->assertSame(25, $this->loyalty()->balanceFor($user));
    }

    public function test_returning_visitor_earns_a_daily_checkin_not_first_scan(): void
    {
        config()->set('loyalty.earn.first_scan', 25);
        config()->set('loyalty.earn.checkin', 5);
        $user = User::factory()->create();
        $business = Business::factory()->create();

        // Their first scan already happened previously.
        $this->loyalty()->award($user, CoinSource::FirstScan, $business);

        app(VisitService::class)->record($business, $user, Request::create('/', 'GET'));

        $this->assertTrue($this->loyalty()->hasEarned($user, CoinSource::Checkin, $business));
        // Still exactly one first-scan entry.
        $this->assertSame(1, $user->walletTransactions()->where('source', 'first_scan')->count());
        $this->assertSame(30, $this->loyalty()->balanceFor($user));
    }

    public function test_leaving_a_review_earns_coins_once(): void
    {
        config()->set('loyalty.earn.review', 20);
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $reviews = app(ReviewService::class);

        $reviews->upsert($user, $business, 5, 'Great!');
        $reviews->upsert($user, $business, 4, 'Edited'); // editing must not re-earn

        $this->assertSame(1, $user->walletTransactions()->where('source', 'review')->count());
        $this->assertSame(20, $this->loyalty()->balanceFor($user));
    }

    public function test_completing_a_redemption_earns_coins(): void
    {
        config()->set('loyalty.earn.redeem', 15);
        $customer = User::factory()->create();
        $business = Business::factory()->create();
        $reward = Reward::factory()->create([
            'customer_id' => $customer->id,
            'business_id' => $business->id,
        ]);

        app(RedemptionService::class)->redeem($business, $reward->code, $business->owner);

        $this->assertTrue($this->loyalty()->hasEarned($customer, CoinSource::Redeem, $business));
        $this->assertSame(15, $this->loyalty()->balanceFor($customer));
    }

    public function test_new_customer_gets_a_one_time_welcome_bonus(): void
    {
        config()->set('loyalty.earn.welcome', 50);

        $session = app(AuthService::class)->devLogin('newbie@example.com');
        $user = $session['user'];

        $this->assertSame(50, $this->loyalty()->balanceFor($user));

        // Logging in again does not re-grant it.
        app(AuthService::class)->devLogin('newbie@example.com');
        $this->assertSame(50, $this->loyalty()->balanceFor($user->fresh()));
    }
}
