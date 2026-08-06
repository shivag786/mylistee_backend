<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function adminToken(): string
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active])
            ->createToken('api')->plainTextToken;
    }

    public function test_admin_can_create_a_banner(): void
    {
        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/banners', [
                'title' => 'Diwali Sale',
                'placement' => 'home_top',
                'image' => UploadedFile::fake()->image('promo.jpg', 1200, 450),
                'link_url' => 'https://example.com/sale',
                'is_active' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Diwali Sale')
            ->assertJsonPath('data.placement', 'home_top');

        $this->assertDatabaseHas('banners', ['title' => 'Diwali Sale', 'placement' => 'home_top']);
    }

    public function test_schedule_time_round_trips_without_timezone_shift(): void
    {
        $token = $this->adminToken();

        $created = $this->withToken($token)
            ->post('/api/v1/admin/banners', [
                'title' => 'Scheduled',
                'placement' => 'home_top',
                'image' => UploadedFile::fake()->image('promo.jpg', 1200, 450),
                'starts_at' => '2026-08-01T09:30',
                'ends_at' => '2026-08-05T18:00',
            ])
            ->assertCreated();

        // The exact wall-clock time the admin typed comes back unchanged.
        $created->assertJsonPath('data.startsAt', '2026-08-01T09:30');
        $created->assertJsonPath('data.endsAt', '2026-08-05T18:00');
    }

    public function test_banner_creation_requires_admin(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/banners', ['title' => 'x', 'placement' => 'home_top'])
            ->assertForbidden();
    }

    public function test_public_feed_only_returns_live_banners_grouped_by_placement(): void
    {
        // Active, no schedule → live.
        Banner::create(['title' => 'Live', 'image_path' => 'banners/a.jpg', 'placement' => 'home_top', 'is_active' => true]);
        // Inactive → hidden.
        Banner::create(['title' => 'Off', 'image_path' => 'banners/b.jpg', 'placement' => 'home_top', 'is_active' => false]);
        // Scheduled in the future → hidden.
        Banner::create(['title' => 'Future', 'image_path' => 'banners/c.jpg', 'placement' => 'home_after_combos', 'is_active' => true, 'starts_at' => now()->addDay()]);
        // Expired → hidden.
        Banner::create(['title' => 'Past', 'image_path' => 'banners/d.jpg', 'placement' => 'home_after_combos', 'is_active' => true, 'ends_at' => now()->subDay()]);

        $data = $this->getJson('/api/v1/banners')->assertOk()->json('data');

        $this->assertCount(1, $data['home_top']);
        $this->assertSame('Live', $data['home_top'][0]['title']);
        $this->assertCount(0, $data['home_after_combos']);
    }

    public function test_toggle_hides_banner_from_feed(): void
    {
        $banner = Banner::create(['title' => 'Sale', 'image_path' => 'banners/a.jpg', 'placement' => 'home_top', 'is_active' => true]);

        $this->withToken($this->adminToken())
            ->patchJson("/api/v1/admin/banners/{$banner->uuid}/toggle")
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->assertCount(0, $this->getJson('/api/v1/banners')->json('data.home_top'));
    }
}
