<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin-set theme (colours, fonts) and the shop-card colour switch, as saved by
 * the admin and as every device reads them from GET /config.
 */
class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_config_serves_the_default_theme(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.theme.colors.primary', '#e23744')
            ->assertJsonPath('data.theme.colors.text', '#212121')
            ->assertJsonPath('data.theme.font', 'Metropolis')
            ->assertJsonPath('data.theme.menuCardColors', true);
    }

    public function test_admin_changes_reach_every_device_through_config(): void
    {
        $admin = $this->admin();

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/settings', [
                'themePrimary' => '#1d4ed8',
                'themeFont' => 'Nunito',
                'themeHeadingFont' => 'Playfair Display',
                'menuCardColors' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.themePrimary', '#1d4ed8');

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.theme.colors.primary', '#1d4ed8')
            ->assertJsonPath('data.theme.font', 'Nunito')
            ->assertJsonPath('data.theme.headingFont', 'Playfair Display')
            ->assertJsonPath('data.theme.menuCardColors', false);
    }

    public function test_only_hex_colours_and_listed_fonts_are_accepted(): void
    {
        $token = $this->admin()->createToken('api')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/settings', ['themePrimary' => 'red'])
            ->assertStatus(422);
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['themePrimary' => '#fff'])
            ->assertStatus(422);
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['themeFont' => 'Comic Sans MS'])
            ->assertStatus(422);
    }

    public function test_customers_cannot_change_the_theme(): void
    {
        $customer = User::factory()->create();

        $this->withToken($customer->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/settings', ['themePrimary' => '#000000'])
            ->assertForbidden();
    }
}
