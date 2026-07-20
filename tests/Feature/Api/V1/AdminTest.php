<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\CmsPageSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(CmsPageSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_dashboard_returns_platform_stats(): void
    {
        Business::factory()->count(2)->create();
        User::factory()->count(3)->create(); // customers

        $this->withToken($this->token($this->admin()))
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['stats' => ['totalCustomers', 'totalBusinesses', 'revenueTotal'], 'growth', 'health'],
            ])
            ->assertJsonPath('data.stats.totalBusinesses', 2);
    }

    public function test_admin_can_list_and_suspend_a_business(): void
    {
        $business = Business::factory()->create();

        $this->withToken($this->token($this->admin()))
            ->getJson('/api/v1/admin/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.id', $business->uuid);

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/businesses/{$business->uuid}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'status' => 'suspended']);
        // The action is audited.
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.status']);
    }

    public function test_admin_can_verify_a_business(): void
    {
        $business = Business::factory()->create(['verified' => false]);

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/businesses/{$business->uuid}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified', true);
    }

    public function test_admin_can_block_a_customer_and_revoke_tokens(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $customer->createToken('api'); // an existing session

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/customers/{$customer->uuid}/status", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        $this->assertSame(0, $customer->tokens()->count()); // sessions revoked
    }

    public function test_hiding_a_review_recalculates_the_business_rating(): void
    {
        $business = Business::factory()->create();
        $review = Review::factory()->create([
            'business_id' => $business->id,
            'rating' => 5,
            'status' => 'published',
        ]);
        $business->recalculateRating();
        $this->assertEquals(5, $business->fresh()->average_rating);

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'hidden'])
            ->assertOk();

        $this->assertEquals(0, $business->fresh()->average_rating);
    }

    public function test_admin_can_edit_a_plan_limit(): void
    {
        $this->withToken($this->token($this->admin()))
            ->patchJson('/api/v1/admin/plans/free', ['maxActiveOffers' => 5])
            ->assertOk()
            ->assertJsonPath('data.limits.maxActiveOffers', 5);

        $this->assertDatabaseHas('plans', ['key' => 'free', 'max_active_offers' => 5]);
    }

    public function test_admin_can_broadcast_to_customers(): void
    {
        User::factory()->count(3)->create(['role' => UserRole::Customer]);

        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/broadcast', [
                'title' => 'New rewards!',
                'body' => 'Check out fresh offers near you.',
                'target' => 'customers',
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', 3);

        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_admin_can_toggle_a_feature_flag(): void
    {
        $this->withToken($this->token($this->admin()))
            ->patchJson('/api/v1/admin/feature-flags/scratch_cards', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    public function test_admin_can_update_settings_and_cms(): void
    {
        $admin = $this->admin();

        $this->withToken($this->token($admin))
            ->putJson('/api/v1/admin/settings', ['brandName' => 'Listee Pro', 'maintenanceMode' => true])
            ->assertOk()
            ->assertJsonPath('data.brandName', 'Listee Pro')
            ->assertJsonPath('data.maintenanceMode', true);

        $this->withToken($this->token($admin))
            ->putJson('/api/v1/admin/cms/about', ['title' => 'About Listee', 'body' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('data.title', 'About Listee');
    }

    public function test_audit_log_endpoint_lists_actions(): void
    {
        $admin = $this->admin();
        $business = Business::factory()->create();
        $this->withToken($this->token($admin))
            ->patchJson("/api/v1/admin/businesses/{$business->uuid}/verify")->assertOk();

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'business.verify')
            ->assertJsonPath('data.0.actorName', $admin->name);
    }

    public function test_report_export_streams_csv(): void
    {
        Business::factory()->count(2)->create();

        $response = $this->withToken($this->token($this->admin()))
            ->get('/api/v1/admin/reports/businesses');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('Name,Owner,Email', $response->streamedContent());
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->withToken($this->token($customer))
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
    }
}
