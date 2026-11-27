<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendBroadcastNotification;
use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\CmsPageSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_dashboard_lists_who_joined_lately(): void
    {
        $shop = Business::factory()->create(['name' => 'Chai Point']);
        $old = User::factory()->create(['name' => 'Old', 'created_at' => now()->subDays(20)]);
        $new = User::factory()->create(['name' => 'Asha']);
        \App\Models\BusinessVisit::create(['business_id' => $shop->id, 'customer_id' => $new->id]);

        $res = $this->withToken($this->token($this->admin()))->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.stats.newCustomersWeek', 1)
            ->assertJsonPath('data.stats.newBusinessesToday', 1)
            ->assertJsonPath('data.recentCustomers.0.name', 'Asha')
            ->assertJsonPath('data.recentCustomers.0.firstShop.name', 'Chai Point')
            ->assertJsonPath('data.recentBusinesses.0.name', 'Chai Point');
        $this->assertSame('Old', $res->json('data.recentCustomers.1.name'));
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

    public function test_broadcast_fans_out_after_the_response(): void
    {
        Queue::fake();
        User::factory()->count(2)->create(['role' => UserRole::Customer]);
        User::factory()->count(3)->create(['role' => UserRole::BusinessOwner]);

        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/broadcast', [
                'title' => 'Platform update',
                'target' => 'all',
            ])
            ->assertOk()
            // 2 customers + 3 owners + the admin sender = 6 active users.
            ->assertJsonPath('data.sent', 6);

        // The request returned without doing the fan-out — the job runs after the
        // response (no queue worker needed on the server).
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertPushed(SendBroadcastNotification::class, function ($job) {
            $b = \App\Models\AdminBroadcast::find($job->broadcastId);

            return $b->target === 'all' && $b->title === 'Platform update' && $b->recipients === 6;
        });
    }

    public function test_broadcast_reaches_one_shops_customers_and_records_history(): void
    {
        $owner = User::factory()->create(['role' => UserRole::BusinessOwner]);
        $shop = \App\Models\Business::factory()->create(['owner_id' => $owner->id]);
        $fan = User::factory()->create(['role' => UserRole::Customer]);
        User::factory()->create(['role' => UserRole::Customer]); // not connected
        \Illuminate\Support\Facades\DB::table('favorite_businesses')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $fan->id, 'business_id' => $shop->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->admin();

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/broadcast/preview?target=shop_customers&businessId='.$shop->uuid.'&audience=followers')
            ->assertOk()
            ->assertJsonPath('data.recipients', 1)
            ->assertJsonPath('data.reachable', 0);

        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/broadcast', [
                'title' => 'Weekend offer', 'target' => 'shop_customers',
                'businessId' => $shop->uuid, 'audience' => 'followers', 'link' => '/b/'.$shop->slug,
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertDatabaseHas('notifications', ['user_id' => $fan->id, 'title' => 'Weekend offer']);
        $this->assertDatabaseCount('notifications', 1);

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/broadcast')
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'sent')
            ->assertJsonPath('data.items.0.recipients', 1);
    }

    public function test_broadcast_test_goes_only_to_the_admin(): void
    {
        User::factory()->count(2)->create(['role' => UserRole::Customer]);
        $admin = $this->admin();

        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/broadcast', ['title' => 'Test', 'target' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'title' => 'Test']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_insight_pages_respond(): void
    {
        $owner = User::factory()->create(['role' => UserRole::BusinessOwner]);
        \App\Models\Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $token = $this->token($this->admin());

        foreach (['active', 'expiring', 'expired', 'cancelled', 'all'] as $status) {
            $this->withToken($token)->getJson('/api/v1/admin/subscriptions?status='.$status)->assertOk()->assertJsonStructure(['data' => ['summary', 'items', 'meta']]);
        }
        $this->withToken($token)->getJson('/api/v1/admin/vendor-health')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.issues.0.key', 'no_logo');
        $this->withToken($token)->getJson('/api/v1/admin/vendor-health?issue=no_products')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->withToken($token)->getJson('/api/v1/admin/qr-scans?days=7')->assertOk()->assertJsonCount(7, 'data.series');
    }

    public function test_admin_can_change_owner_login_mobile(): void
    {
        $owner = User::factory()->create(['role' => UserRole::BusinessOwner, 'phone' => '9000000099', 'pin' => '4321']);
        $shop = \App\Models\Business::factory()->create(['owner_id' => $owner->id]);
        User::factory()->create(['phone' => '9888888888']);
        $token = $this->token($this->admin());

        $this->withToken($token)->putJson('/api/v1/admin/businesses/'.$shop->uuid, ['name' => $shop->name, 'owner_mobile' => '9888888888'])
            ->assertStatus(422)->assertJsonValidationErrors('owner_mobile');
        $this->withToken($token)->putJson('/api/v1/admin/businesses/'.$shop->uuid, ['name' => $shop->name, 'owner_mobile' => '12345'])
            ->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/admin/businesses/'.$shop->uuid, ['name' => $shop->name, 'owner_mobile' => '9123456789'])
            ->assertOk()
            ->assertJsonPath('data.ownerMobile', '9123456789');
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.owner_mobile']);

        // The owner signs in with the new number and the same PIN.
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9123456789', 'pin' => '4321'])->assertOk();
    }

    public function test_website_enquiry_reaches_admins(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/enquiries', [
            'name' => 'Ravi', 'phone' => '98765 43210', 'city' => 'Jhansi',
            'businessName' => 'Ravi Sweets', 'topic' => 'shop', 'source' => '/pos-software/jhansi',
        ])->assertCreated();
        $this->postJson('/api/v1/enquiries', ['name' => 'Bot', 'phone' => '1234567890', 'website' => 'spam.com'])->assertOk();
        $this->postJson('/api/v1/enquiries', ['name' => 'X', 'phone' => 'abc'])->assertStatus(422);

        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'title' => 'New enquiry from Jhansi: Ravi']);

        $id = $this->withToken($this->token($admin))->getJson('/api/v1/admin/enquiries')
            ->assertOk()->assertJsonPath('data.counts.new', 1)->json('data.items.0.id');
        $this->withToken($this->token($admin))->patchJson('/api/v1/admin/enquiries/'.$id, ['status' => 'contacted', 'notes' => 'Demo on Monday'])
            ->assertOk()->assertJsonPath('data.status', 'contacted');
    }

    public function test_app_version_gates_are_saved_and_public(): void
    {
        $this->withToken($this->token($this->admin()))
            ->putJson('/api/v1/admin/settings', ['vendorAppMinVersion' => '1.2.1', 'customerAppLatestVersion' => '1.1.1'])
            ->assertOk();
        $this->withToken($this->token($this->admin()))
            ->putJson('/api/v1/admin/settings', ['vendorAppMinVersion' => 'abc'])
            ->assertStatus(422);

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.apps.vendor.minVersion', '1.2.1')
            ->assertJsonPath('data.apps.customer.latestVersion', '1.1.1');
    }

    public function test_admin_can_save_play_store_links_and_config_shows_them(): void
    {
        $url = 'https://play.google.com/store/apps/details?id=org.listee.app';
        $this->withToken($this->token($this->admin()))
            ->putJson('/api/v1/admin/settings', ['playStoreCustomerUrl' => $url, 'playStoreVendorUrl' => ''])
            ->assertOk()
            ->assertJsonPath('data.playStoreCustomerUrl', $url);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.apps.customerPlayStore', $url)
            ->assertJsonPath('data.apps.vendorPlayStore', '');
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

    public function test_admin_uploads_order_sound_and_it_surfaces_in_config(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $admin = $this->admin();

        // Upload → stored + exposed to the public app config.
        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/settings/order-sound', [
                'sound' => \Illuminate\Http\Testing\File::create('ding.mp3', 8, 'audio/mpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.orderSoundUrl', fn ($url) => is_string($url) && $url !== '');

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.orderSoundUrl', fn ($url) => is_string($url) && $url !== '');

        // Remove → back to the built-in ding (null).
        $this->withToken($this->token($admin))
            ->deleteJson('/api/v1/admin/settings/order-sound')
            ->assertOk()
            ->assertJsonPath('data.orderSoundUrl', null);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.orderSoundUrl', null);
    }

    public function test_order_sound_upload_rejects_non_audio(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/settings/order-sound', [
                'sound' => \Illuminate\Http\Testing\File::create('evil.php', 4, 'text/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sound']);
    }

    public function test_owner_menu_module_toggle_reflects_in_public_config(): void
    {
        // Default: every owner module is on.
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.ownerModules.orders', true);

        // Admin hides the Orders module from owners.
        $this->withToken($this->token($this->admin()))
            ->patchJson('/api/v1/admin/feature-flags/owner_orders', ['enabled' => false])
            ->assertOk();

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.ownerModules.orders', false)
            ->assertJsonPath('data.ownerModules.products', true);
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

    public function test_admin_can_set_business_coordinates(): void
    {
        // A shop registered without coordinates: invisible to distance sorting
        // until someone fills them in.
        $business = Business::factory()->create(['latitude' => null, 'longitude' => null]);

        $this->withToken($this->token($this->admin()))
            ->putJson("/api/v1/admin/businesses/{$business->uuid}", [
                'name' => $business->name,
                'latitude' => 25.4484,
                'longitude' => 78.5685,
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 25.4484)
            ->assertJsonPath('data.longitude', 78.5685);

        $business->refresh();
        $this->assertSame('25.4484000', (string) $business->latitude);
        $this->assertSame('78.5685000', (string) $business->longitude);
    }

    public function test_business_coordinates_are_validated(): void
    {
        $business = Business::factory()->create();

        $this->withToken($this->token($this->admin()))
            ->putJson("/api/v1/admin/businesses/{$business->uuid}", [
                'name' => $business->name,
                'latitude' => 999,
                'longitude' => 78.5685,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');
    }

    public function test_admin_business_list_exposes_coordinates(): void
    {
        Business::factory()->create(['latitude' => 25.4484, 'longitude' => 78.5685]);

        $this->withToken($this->token($this->admin()))
            ->getJson('/api/v1/admin/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.latitude', 25.4484)
            ->assertJsonPath('data.0.longitude', 78.5685);
    }
}
