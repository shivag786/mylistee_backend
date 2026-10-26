<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->businessOwner()->create();
    }

    /** Authenticate as the given user and return the token header helper. */
    private function actingAsToken(User $user): static
    {
        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_categories_endpoint_is_public_and_lists_active_categories(): void
    {
        BusinessCategory::factory()->count(3)->create();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'icon']]]);
    }

    public function test_business_owner_can_register_a_business_and_gets_a_qr(): void
    {
        $owner = $this->owner();
        $category = BusinessCategory::factory()->create();

        $response = $this->actingAsToken($owner)->postJson('/api/v1/business', [
            'name' => 'Chai Point',
            'category' => $category->uuid,
            'phone' => '9876543210',
            'openingTime' => '09:00',
            'closingTime' => '21:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Chai Point')
            ->assertJsonPath('data.category.id', $category->uuid)
            ->assertJsonStructure(['data' => ['id', 'slug', 'qr' => ['url'], 'status']]);

        $this->assertDatabaseHas('businesses', ['name' => 'Chai Point', 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('qr_codes', ['type' => 'primary']);
    }

    public function test_web_registration_is_live_straight_away(): void
    {
        $category = BusinessCategory::factory()->create();

        $this->actingAsToken($this->owner())->postJson('/api/v1/business', [
            'name' => 'Chai Point',
            'category' => $category->uuid,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.setupComplete', true);
    }

    public function test_a_draft_shop_from_the_app_stays_hidden_until_set_up(): void
    {
        $owner = $this->owner();
        $category = BusinessCategory::factory()->create();

        // Started with just a name and category, to add products first.
        $slug = $this->actingAsToken($owner)->postJson('/api/v1/business', [
            'name' => 'Samosa Corner',
            'category' => $category->uuid,
            'draft' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.setupComplete', false)
            ->assertJsonPath('data.setupMissing', ['address', 'city'])
            ->json('data.slug');

        // Customers can't see it, but the owner can already build the menu.
        $this->getJson("/api/v1/businesses/{$slug}")->assertNotFound();
        $this->postJson('/api/v1/business/products', ['name' => 'Samosa', 'selling_price' => 20])->assertCreated();

        // Going live needs the address first.
        $this->postJson('/api/v1/business/setup/complete')
            ->assertStatus(422)
            ->assertJsonPath('errors.setup.0', "Add your shop's address, city to go live.");

        $this->putJson('/api/v1/business/profile', [
            'name' => 'Samosa Corner',
            'category' => $category->uuid,
            'address' => '12 MG Road',
            'city' => 'Lucknow',
        ])->assertOk();

        $this->postJson('/api/v1/business/setup/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.setupComplete', true);

        $this->getJson("/api/v1/businesses/{$slug}")->assertOk();
    }

    public function test_finishing_setup_does_not_lift_an_admin_suspension(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'suspended',
            'address' => '12 MG Road',
            'city' => 'Lucknow',
            'setup_completed_at' => null,
        ]);

        $this->actingAsToken($owner)->postJson('/api/v1/business/setup/complete')
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertNotNull($business->fresh()->setup_completed_at);
    }

    public function test_dashboard_shows_todays_sales_and_a_week_trend(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $order = fn (array $a) => Order::factory()->create(['business_id' => $business->id] + $a);

        $order(['status' => 'paid', 'total' => 200, 'vendor_earning' => 190, 'paid_at' => now()]);
        $order(['status' => 'completed', 'total' => 100, 'platform_fee' => 2, 'paid_at' => now()]);
        $order(['status' => 'completed', 'total' => 50, 'paid_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        $order(['status' => 'placed', 'total' => 80]);
        $order(['status' => 'cancelled', 'total' => 999]);

        $sales = $this->actingAsToken($owner)->getJson('/api/v1/business/dashboard')
            ->assertOk()->json('data.sales');

        $this->assertSame(3, $sales['todayOrders']);        // paid, completed, placed
        $this->assertEquals(300, $sales['todaySales']);
        $this->assertEquals(288, $sales['todayEarnings']);  // 190 + (100 - 2)
        $this->assertSame(1, $sales['waitingOrders']);
        $this->assertEquals(350, $sales['weekSales']);
        $this->assertCount(7, $sales['series']);
        $this->assertEquals(300, end($sales['series'])['sales']);
    }

    public function test_registration_generates_a_unique_slug_on_collision(): void
    {
        $category = BusinessCategory::factory()->create();
        Business::factory()->create(['slug' => 'chai-point']);

        $this->actingAsToken($this->owner())->postJson('/api/v1/business', [
            'name' => 'Chai Point',
            'category' => $category->uuid,
        ])->assertStatus(201)
            ->assertJsonPath('data.slug', 'chai-point-2');
    }

    public function test_owner_cannot_register_a_second_business(): void
    {
        $owner = $this->owner();
        $category = BusinessCategory::factory()->create();
        Business::factory()->create(['owner_id' => $owner->id]);

        $this->actingAsToken($owner)->postJson('/api/v1/business', [
            'name' => 'Second Shop',
            'category' => $category->uuid,
        ])->assertStatus(409);
    }

    public function test_registration_validates_required_fields(): void
    {
        $this->actingAsToken($this->owner())->postJson('/api/v1/business', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category']);
    }

    public function test_customer_role_cannot_access_business_endpoints(): void
    {
        $customer = User::factory()->create(); // default role = customer

        $this->actingAsToken($customer)->getJson('/api/v1/business/dashboard')
            ->assertStatus(403);
    }

    public function test_owner_can_view_their_profile_and_dashboard(): void
    {
        $owner = $this->owner();
        Business::factory()->create(['owner_id' => $owner->id, 'name' => 'My Cafe']);

        $this->actingAsToken($owner)->getJson('/api/v1/business/profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'My Cafe');

        $this->actingAsToken($owner)->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.metrics.todayVisitors', 0)
            ->assertJsonStructure([
                'data' => ['business', 'metrics', 'onboarding', 'plan' => ['key', 'name', 'maxActiveOffers']],
            ]);
    }

    public function test_profile_returns_404_when_owner_has_no_business(): void
    {
        $this->actingAsToken($this->owner())->getJson('/api/v1/business/profile')
            ->assertStatus(404);
    }

    public function test_owner_can_update_their_business(): void
    {
        $owner = $this->owner();
        Business::factory()->create(['owner_id' => $owner->id]);

        $this->actingAsToken($owner)->putJson('/api/v1/business/profile', [
            'name' => 'Updated Name',
            'description' => 'Now with more chai',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.description', 'Now with more chai');
    }

    public function test_owner_can_add_and_remove_gallery_images(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        Business::factory()->create(['owner_id' => $owner->id]);

        $add = $this->actingAsToken($owner)->postJson('/api/v1/business/gallery', [
            'image' => UploadedFile::fake()->image('shop.jpg', 800, 600),
        ]);

        $add->assertStatus(201)->assertJsonStructure(['data' => ['id', 'url']]);
        $uuid = $add->json('data.id');
        $this->assertDatabaseCount('business_gallery', 1);

        $this->actingAsToken($owner)->deleteJson("/api/v1/business/gallery/{$uuid}")
            ->assertOk();
        $this->assertSoftDeleted('business_gallery', ['uuid' => $uuid]);
    }

    public function test_owner_cannot_delete_another_businesss_gallery_image(): void
    {
        $ownerA = $this->owner();
        $businessB = Business::factory()->create();
        $image = $businessB->gallery()->create([
            'image_path' => 'x.jpg', 'sort_order' => 1, 'status' => 'active',
        ]);
        Business::factory()->create(['owner_id' => $ownerA->id]);

        $this->actingAsToken($ownerA)->deleteJson("/api/v1/business/gallery/{$image->uuid}")
            ->assertStatus(404);
        $this->assertDatabaseHas('business_gallery', ['uuid' => $image->uuid, 'deleted_at' => null]);
    }

    public function test_qr_download_increments_the_counter(): void
    {
        $owner = $this->owner();
        Business::factory()->create(['owner_id' => $owner->id]);

        // First GET creates/returns the QR.
        $this->actingAsToken($owner)->getJson('/api/v1/business/qr')
            ->assertOk()
            ->assertJsonPath('data.downloadCount', 0);

        $this->actingAsToken($owner)->postJson('/api/v1/business/qr/download')
            ->assertOk()
            ->assertJsonPath('data.downloadCount', 1);
    }

    public function test_business_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/business/profile')->assertStatus(401);
    }
}
