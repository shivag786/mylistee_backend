<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firebase_uid' => 'test_'.fake()->unique()->numerify('##########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'avatar_url' => null,
            'phone' => null,
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
            'provider' => 'google',
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** A user with the Business Owner role. */
    public function businessOwner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::BusinessOwner]);
    }

    /** A user with the Admin role. */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Admin]);
    }

    /** A suspended account (cannot authenticate). */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Suspended]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
