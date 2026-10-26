<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Business app theme, served to the vendor app through GET /config. */
class VendorAppThemeTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_config_serves_the_business_brand_by_default(): void
    {
        $this->getJson('/api/v1/config')->assertOk()
            ->assertJsonPath('data.vendorApp.primary', '#FAC83A')
            ->assertJsonPath('data.vendorApp.secondary', '#198077')
            ->assertJsonPath('data.vendorApp.background', '#FEFBF3')
            ->assertJsonPath('data.vendorApp.splashSeconds', 3);
    }

    public function test_admin_changes_colours_and_can_reset(): void
    {
        $admin = User::factory()->admin()->create();

        $this->token($admin)->putJson('/api/v1/admin/vendor-app-theme', ['primary' => '#ff8800', 'splashSeconds' => 4])
            ->assertOk()
            ->assertJsonPath('data.theme.primary', '#FF8800')
            // Untouched colours keep the brand.
            ->assertJsonPath('data.theme.secondary', '#198077');

        $this->getJson('/api/v1/config')->assertJsonPath('data.vendorApp.primary', '#FF8800')
            ->assertJsonPath('data.vendorApp.splashSeconds', 4);

        $this->token($admin)->putJson('/api/v1/admin/vendor-app-theme', ['primary' => 'orange'])->assertUnprocessable();

        $this->token($admin)->deleteJson('/api/v1/admin/vendor-app-theme')->assertOk()
            ->assertJsonPath('data.theme.primary', '#FAC83A');
    }

    public function test_only_admins_can_change_it(): void
    {
        $owner = User::factory()->businessOwner()->create();

        $this->token($owner)->putJson('/api/v1/admin/vendor-app-theme', ['primary' => '#000000'])->assertForbidden();
    }
}
