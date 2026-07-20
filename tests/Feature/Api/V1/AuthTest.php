<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a FirebaseService whose token verification returns the given claims,
     * so tests never hit the real Firebase (backend is source of truth).
     *
     * @param  array<string, mixed>|null  $claims
     */
    private function fakeFirebase(?array $claims): void
    {
        $this->mock(FirebaseService::class, function (MockInterface $mock) use ($claims): void {
            $mock->shouldReceive('verifyIdToken')->andReturn($claims);
        });
    }

    public function test_google_login_creates_a_user_and_returns_a_token(): void
    {
        $this->fakeFirebase([
            'sub' => 'firebase-uid-1',
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'picture' => 'https://img/ada.png',
            'email_verified' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/google', ['idToken' => 'valid-token']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'photoUrl', 'role']]]);

        $this->assertDatabaseHas('users', [
            'firebase_uid' => 'firebase-uid-1',
            'email' => 'ada@example.com',
            'provider' => 'google',
        ]);
    }

    public function test_google_login_reuses_an_existing_user(): void
    {
        $existing = User::factory()->create([
            'firebase_uid' => 'firebase-uid-2',
            'email' => 'grace@example.com',
        ]);

        $this->fakeFirebase([
            'sub' => 'firebase-uid-2',
            'email' => 'grace@example.com',
            'name' => 'Grace Hopper',
        ]);

        $this->postJson('/api/v1/auth/google', ['idToken' => 'valid-token'])->assertOk();

        $this->assertSame(1, User::where('email', 'grace@example.com')->count());
        $this->assertNotNull($existing->fresh()->last_login_at);
    }

    public function test_google_login_rejects_an_unverifiable_token(): void
    {
        $this->fakeFirebase(null);

        $this->postJson('/api/v1/auth/google', ['idToken' => 'bad-token'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_google_login_requires_an_id_token(): void
    {
        $this->postJson('/api/v1/auth/google', [])->assertStatus(422);
    }

    public function test_suspended_account_cannot_sign_in(): void
    {
        User::factory()->suspended()->create([
            'firebase_uid' => 'firebase-uid-3',
            'email' => 'suspended@example.com',
        ]);

        $this->fakeFirebase([
            'sub' => 'firebase-uid-3',
            'email' => 'suspended@example.com',
            'name' => 'Suspended',
        ]);

        $this->postJson('/api/v1/auth/google', ['idToken' => 'valid-token'])
            ->assertStatus(403);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->uuid)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_unauthenticated_api_request_returns_401_even_without_json_accept_header(): void
    {
        // A guest hitting an API route without an Accept header must get a 401
        // envelope, never a 500 from redirecting to a nonexistent `login` route.
        $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_suspended_user_is_blocked_from_protected_routes(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('api')->plainTextToken;
        $user->update(['status' => UserStatus::Suspended]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_dev_login_creates_a_user_in_local_environment(): void
    {
        $response = $this->postJson('/api/v1/auth/dev-login', [
            'email' => 'dev@example.com',
            'name' => 'Dev Tester',
            'role' => 'business_owner',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'dev@example.com')
            ->assertJsonPath('data.user.role', 'business_owner');

        $this->assertDatabaseHas('users', [
            'email' => 'dev@example.com',
            'provider' => 'dev',
            'role' => UserRole::BusinessOwner->value,
        ]);
    }
}
