<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_sign_in_with_mobile_and_pin(): void
    {
        $owner = User::factory()->create([
            'phone' => '9000000002',
            'pin' => '1234',
            'role' => UserRole::BusinessOwner,
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000002', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $owner->uuid)
            ->assertJsonPath('data.user.role', 'business_owner')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_pin_login_also_accepts_email_as_identifier(): void
    {
        User::factory()->create([
            'email' => 'admin@listee.test',
            'pin' => '1234',
            'role' => UserRole::Admin,
        ]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => 'admin@listee.test', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_pin_login_rejects_a_wrong_pin(): void
    {
        User::factory()->create(['phone' => '9000000002', 'pin' => '1234']);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000002', 'pin' => '9999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_pin_login_locks_out_after_repeated_failures(): void
    {
        User::factory()->create(['phone' => '9000000002', 'pin' => '1234']);

        // Five wrong attempts are each rejected as invalid credentials.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000002', 'pin' => '0000'])
                ->assertStatus(422);
        }

        // The sixth is locked out — even with the correct PIN.
        $response = $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000002', 'pin' => '1234'])
            ->assertStatus(422);

        $this->assertStringContainsString('Too many attempts', $response->json('errors.pin.0'));
    }

    public function test_pin_login_rejects_an_account_without_a_pin(): void
    {
        // Customers (Google-only) have no PIN set.
        User::factory()->create(['phone' => '9000000003', 'pin' => null]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000003', 'pin' => '1234'])
            ->assertStatus(422);
    }

    public function test_pin_login_blocks_a_suspended_account(): void
    {
        User::factory()->create([
            'phone' => '9000000002',
            'pin' => '1234',
            'status' => UserStatus::Suspended,
        ]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000002', 'pin' => '1234'])
            ->assertStatus(403);
    }

    public function test_public_owner_signup_creates_an_active_owner_and_signs_in(): void
    {
        $this->postJson('/api/v1/auth/register-owner', [
            'name' => 'Priya Shah',
            'mobile' => '9123456780',
            'pin' => '4321',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.user.role', 'business_owner')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'phone' => '9123456780',
            'role' => 'business_owner',
            'pin_plain' => '4321',
        ]);

        // The new owner can immediately sign in with those credentials.
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9123456780', 'pin' => '4321'])
            ->assertOk();
    }

    public function test_owner_signup_rejects_a_duplicate_mobile(): void
    {
        User::factory()->create(['phone' => '9123456780']);

        $this->postJson('/api/v1/auth/register-owner', [
            'name' => 'Someone',
            'mobile' => '9123456780',
            'pin' => '1111',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_owner_signup_requires_a_numeric_pin(): void
    {
        $this->postJson('/api/v1/auth/register-owner', [
            'name' => 'Someone',
            'mobile' => '9123456781',
            'pin' => 'abcd',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }
}
