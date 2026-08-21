<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\CategoryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::BusinessOwner, 'status' => UserStatus::Active]);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_admin_can_list_categories_with_business_count(): void
    {
        $category = BusinessCategory::factory()->create();
        Business::factory()->create(['category_id' => $category->id]);

        $this->withToken($this->token($this->admin()))
            ->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->uuid)
            ->assertJsonPath('data.0.businessCount', 1);
    }

    public function test_admin_can_create_a_category_with_image_and_defaults(): void
    {
        Storage::fake('public');

        $this->withToken($this->token($this->admin()))
            ->post('/api/v1/admin/categories', [
                'name' => 'Food & Drinks',
                'image' => UploadedFile::fake()->image('food.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Food & Drinks')
            ->assertJsonPath('data.slug', 'food-drinks')
            ->assertJsonPath('data.altText', 'Food & Drinks'); // auto from name

        $category = BusinessCategory::first();
        $this->assertNotNull($category->image_path);
        Storage::disk('public')->assertExists($category->image_path);
    }

    public function test_a_new_category_shows_on_the_public_endpoint_immediately(): void
    {
        // Read once first — this used to prime a 6h cache, so a category added
        // afterwards stayed invisible until the admin panel busted it. The
        // public list is uncached now, and this guards that.
        $this->getJson('/api/v1/categories')->assertOk();

        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/categories', ['name' => 'Salon'])
            ->assertCreated();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Salon']);
    }

    public function test_a_plan_edited_outside_the_admin_panel_is_visible_immediately(): void
    {
        // The public plans list was cached for 6 hours and only invalidated by
        // the admin controller, so a price changed by any other route — a direct
        // SQL edit, a seeder — kept serving the old figure. Prices must not go
        // stale, so that cache is gone.
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->getJson('/api/v1/plans')->assertOk();

        \App\Models\Plan::where('key', 'pro')->update(['price' => 1999]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonFragment(['key' => 'pro', 'price' => 1999.0]);
    }

    public function test_admin_can_update_toggle_and_delete(): void
    {
        $category = BusinessCategory::factory()->create(['name' => 'Old']);
        $token = $this->token($this->admin());

        $this->withToken($token)
            ->postJson("/api/v1/admin/categories/{$category->uuid}", [
                'name' => 'New',
                'show_on_homepage' => false,
                '_method' => 'PUT',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.showOnHomepage', false);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/categories/{$category->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('business_categories', ['id' => $category->id]);
    }

    public function test_admin_can_reorder_categories(): void
    {
        $a = BusinessCategory::factory()->create(['sort_order' => 1]);
        $b = BusinessCategory::factory()->create(['sort_order' => 2]);

        $this->withToken($this->token($this->admin()))
            ->patchJson('/api/v1/admin/categories/reorder', ['order' => [$b->uuid, $a->uuid]])
            ->assertOk();

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    public function test_owner_can_request_a_category(): void
    {
        $this->withToken($this->token($this->owner()))
            ->postJson('/api/v1/business/category-requests', ['name' => 'Pet Grooming'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('category_requests', ['name' => 'Pet Grooming', 'status' => 'pending']);
    }

    public function test_admin_approve_creates_category_and_notifies_owner(): void
    {
        $owner = $this->owner();
        $request = CategoryRequest::create([
            'requested_by' => $owner->id,
            'name' => 'Cloud Kitchen',
            'status' => 'pending',
        ]);

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/category-requests/{$request->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('business_categories', ['name' => 'Cloud Kitchen']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'title' => 'Category approved',
        ]);
    }

    public function test_admin_reject_marks_request_and_notifies(): void
    {
        $owner = $this->owner();
        $request = CategoryRequest::create([
            'requested_by' => $owner->id,
            'name' => 'Nope',
            'status' => 'pending',
        ]);

        $this->withToken($this->token($this->admin()))
            ->patchJson("/api/v1/admin/category-requests/{$request->uuid}/reject", ['note' => 'Duplicate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseMissing('business_categories', ['name' => 'Nope']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'title' => 'Category request declined',
        ]);
    }

    public function test_non_admin_cannot_manage_categories(): void
    {
        $this->withToken($this->token($this->owner()))
            ->getJson('/api/v1/admin/categories')
            ->assertForbidden();
    }
}
