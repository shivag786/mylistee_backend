<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin's home page layout, saved and served through GET /config. */
class HomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create(['role' => UserRole::Admin])->createToken('api')->plainTextToken;
    }

    public function test_default_layout_is_null(): void
    {
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.homeLayout', null);
    }

    public function test_admin_reorders_hides_and_renames_sections(): void
    {
        $layout = [
            ['id' => 'reels', 'enabled' => true, 'title' => 'Watch & order', 'slot' => 'all'],
            ['id' => 'hero', 'enabled' => true, 'title' => null, 'slot' => 'all'],
            ['id' => 'festival', 'enabled' => false, 'title' => null, 'slot' => 'all'],
            ['id' => 'combos', 'enabled' => true, 'title' => 'Lunch combos', 'slot' => 'afternoon'],
        ];

        $this->withToken($this->adminToken())->putJson('/api/v1/admin/settings', ['homeLayout' => $layout])->assertOk();

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.homeLayout.0.id', 'reels')
            ->assertJsonPath('data.homeLayout.0.title', 'Watch & order')
            ->assertJsonPath('data.homeLayout.2.enabled', false)
            ->assertJsonPath('data.homeLayout.3.slot', 'afternoon');
    }

    public function test_unknown_or_repeated_sections_are_refused(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->putJson('/api/v1/admin/settings', [
            'homeLayout' => [['id' => 'casino', 'enabled' => true]],
        ])->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/admin/settings', [
            'homeLayout' => [['id' => 'hero', 'enabled' => true], ['id' => 'hero', 'enabled' => false]],
        ])->assertStatus(422);
    }

    public function test_admin_can_reset_to_the_default_order(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['homeLayout' => [['id' => 'hero', 'enabled' => false]]])->assertOk();
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['homeLayout' => null])->assertOk();

        $this->getJson('/api/v1/config')->assertJsonPath('data.homeLayout', null);
    }
}
