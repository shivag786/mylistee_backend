<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function adminToken(): string
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active])
            ->createToken('api')->plainTextToken;
    }

    public function test_revenue_requires_admin(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $this->withToken($owner->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/revenue')
            ->assertForbidden();
    }

    public function test_revenue_lists_subscriptions_with_totals_and_summary(): void
    {
        $business = Business::factory()->create(['name' => 'Cafe Rio']);
        $pro = Plan::where('key', 'pro')->first();

        $sub = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $pro->id,
            'status' => 'active',
            'price' => 1499,
            'interval' => 'month',
        ]);

        Invoice::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $sub->id,
            'amount' => 1499,
            'status' => 'paid',
        ]);

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/revenue')
            ->assertOk()
            ->assertJsonPath('data.rows.0.businessName', 'Cafe Rio')
            ->assertJsonPath('data.rows.0.planName', 'Pro')
            ->assertJsonPath('data.rows.0.totalPaid', 1499);

        // Summary reflects the paid invoice + the active subscription's MRR.
        $this->assertEquals(1499, $res->json('data.summary.totalRevenue'));
        $this->assertSame(1, $res->json('data.summary.activeSubscriptions'));
        $this->assertEquals(1499, $res->json('data.summary.mrr'));
    }

    public function test_mrr_normalizes_yearly_price_to_monthly(): void
    {
        $business = Business::factory()->create();
        $pro = Plan::where('key', 'pro')->first();
        Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $pro->id,
            'status' => 'active',
            'price' => 1200,
            'interval' => 'year',
        ]);

        $mrr = $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/revenue')
            ->assertOk()
            ->json('data.summary.mrr');

        $this->assertEquals(100, $mrr); // 1200 / 12
    }

    public function test_revenue_csv_export_streams(): void
    {
        $business = Business::factory()->create(['name' => 'Cafe Rio']);
        $pro = Plan::where('key', 'pro')->first();
        Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $pro->id,
            'status' => 'active',
            'price' => 1499,
            'interval' => 'month',
        ]);

        $res = $this->withToken($this->adminToken())->get('/api/v1/admin/reports/revenue');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('Cafe Rio', $body);
        $this->assertStringContainsString('Total paid', $body); // header row
    }

    public function test_filter_by_status(): void
    {
        $pro = Plan::where('key', 'pro')->first();
        Subscription::factory()->create(['business_id' => Business::factory(), 'plan_id' => $pro->id, 'status' => 'active', 'price' => 100, 'interval' => 'month']);
        Subscription::factory()->create(['business_id' => Business::factory(), 'plan_id' => $pro->id, 'status' => 'cancelled', 'price' => 100, 'interval' => 'month']);

        $rows = $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/revenue?status=cancelled')
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows[0]['status']);
    }
}
