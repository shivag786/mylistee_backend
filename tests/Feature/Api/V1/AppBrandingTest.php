<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Theme extras (dark mode, corners, …), the app icons and the manifest. */
class AppBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function admin(): static
    {
        return $this->withToken(User::factory()->create(['role' => UserRole::Admin])->createToken('api')->plainTextToken);
    }

    public function test_extra_theme_options_reach_config(): void
    {
        $this->admin()->putJson('/api/v1/admin/settings', [
            'themeHeader' => '#101010',
            'themeStar' => '#ff8800',
            'themeOffer' => '',
            'themeMode' => 'system',
            'themeDarkBackground' => '#000000',
            'themeRadius' => 'pill',
            'themeTextSize' => 'large',
        ])->assertOk();

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.theme.extras.header', '#101010')
            ->assertJsonPath('data.theme.extras.star', '#ff8800')
            ->assertJsonPath('data.theme.extras.offer', null)
            ->assertJsonPath('data.theme.mode', 'system')
            ->assertJsonPath('data.theme.dark.background', '#000000')
            ->assertJsonPath('data.theme.radius', 'pill')
            ->assertJsonPath('data.theme.textSize', 'large');
    }

    public function test_bad_extra_values_are_refused(): void
    {
        $this->admin()->putJson('/api/v1/admin/settings', ['themeMode' => 'neon'])->assertStatus(422);
        $this->admin()->putJson('/api/v1/admin/settings', ['themeHeader' => 'blue'])->assertStatus(422);
        $this->admin()->putJson('/api/v1/admin/settings', ['themeRadius' => 'blob'])->assertStatus(422);
    }

    public function test_icons_are_drawn_on_demand_and_served_as_png(): void
    {
        $response = $this->get('/api/v1/app/icons/icon-192.png')->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        [$w, $h] = getimagesizefromstring($response->getContent());
        $this->assertSame([192, 192], [$w, $h]);
    }

    public function test_unknown_icon_names_404(): void
    {
        $this->get('/api/v1/app/icons/evil.png')->assertNotFound();
    }

    public function test_changing_the_primary_colour_redraws_the_icons_under_a_new_version(): void
    {
        $this->get('/api/v1/app/icons/icon-64.png')->assertOk();
        $before = $this->getJson('/api/v1/config')->json('data.theme.icon.version');

        $this->admin()->putJson('/api/v1/admin/settings', ['themePrimary' => '#2563eb'])->assertOk();

        $after = $this->getJson('/api/v1/config')->json('data.theme.icon.version');
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
    }

    public function test_manifest_follows_the_theme_and_points_at_versioned_icons(): void
    {
        $this->admin()->putJson('/api/v1/admin/settings', ['themePrimary' => '#7c3aed'])->assertOk();

        $manifest = $this->getJson('/api/v1/app/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('#7c3aed', $manifest['theme_color']);
        $this->assertCount(4, $manifest['icons']);
        $this->assertStringContainsString('/app/icons/maskable-512.png?v=', $manifest['icons'][3]['src']);
        $this->assertSame('maskable', $manifest['icons'][3]['purpose']);
    }

    public function test_admin_uploads_an_icon_and_can_go_back_to_the_theme_icon(): void
    {
        $this->admin()->post('/api/v1/admin/settings/app-icon', [
            'icon' => UploadedFile::fake()->image('icon.png', 512, 512),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.appIconMode', 'upload');

        $this->get('/api/v1/app/icons/apple-180.png')->assertOk();

        $this->admin()->deleteJson('/api/v1/admin/settings/app-icon')
            ->assertOk()
            ->assertJsonPath('data.appIconMode', 'auto');
    }

    public function test_tiny_icon_uploads_are_refused(): void
    {
        $this->admin()->post('/api/v1/admin/settings/app-icon', [
            'icon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
