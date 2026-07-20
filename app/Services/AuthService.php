<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Owns the authentication flow (document/phase/12 §Firebase Login Flow).
 *
 * Backend is the source of truth: a Google ID token minted on the client is
 * verified here against Firebase, then mapped to a local user (find-or-create).
 * A Sanctum personal access token is issued for subsequent API calls.
 */
class AuthService
{
    public function __construct(private readonly FirebaseService $firebase) {}

    /**
     * Exchange a verified Firebase ID token for a local session.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException when the token cannot be verified
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException when suspended
     */
    public function loginWithGoogle(string $idToken): array
    {
        $claims = $this->firebase->verifyIdToken($idToken);

        if ($claims === null || empty($claims['sub'])) {
            throw ValidationException::withMessages([
                'idToken' => ['We could not verify your Google sign-in. Please try again.'],
            ]);
        }

        $user = $this->findOrCreateFromClaims($claims);

        return $this->issueSession($user);
    }

    /**
     * Mobile/email + PIN sign-in for business owners and admins (customers use
     * Google). The identifier matches a phone number or email; the PIN is checked
     * against the stored hash. A generic error avoids revealing which accounts exist.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException on bad credentials
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException when suspended
     */
    public function loginWithPin(string $identifier, string $pin): array
    {
        $user = User::where('phone', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user === null || $user->pin === null || ! Hash::check($pin, $user->pin)) {
            throw ValidationException::withMessages([
                'pin' => ['Invalid mobile number or PIN.'],
            ]);
        }

        return $this->issueSession($user);
    }

    /**
     * Public business-owner sign-up (mobile + PIN). Creates an active owner
     * account and signs them in, so they can go straight to registering their
     * business. `pin_plain` is stored for the admin panel (demo only).
     *
     * @return array{user: User, token: string}
     */
    public function registerOwner(string $name, string $phone, string $pin): array
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'pin' => $pin,
            'pin_plain' => $pin,
            'role' => UserRole::BusinessOwner,
            'status' => UserStatus::Active,
            'provider' => 'pin',
        ]);

        return $this->issueSession($user);
    }

    /**
     * Local-only shortcut so the app can be exercised before Firebase creds are
     * wired up. Guarded to non-production by the controller/route.
     *
     * @return array{user: User, token: string}
     */
    public function devLogin(string $email, ?string $name = null, UserRole $role = UserRole::Customer): array
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        if ($user->trashed()) {
            $user->restore();
        }

        $user->fill([
            'name' => $name ?? $user->name ?? 'Dev User',
            'provider' => 'dev',
            'status' => UserStatus::Active,
        ]);

        if (! $user->exists) {
            $user->role = $role;
        }

        $user->save();

        return $this->issueSession($user);
    }

    /**
     * Match an existing user by firebase_uid (or email) or create a new one.
     *
     * @param  array<string, mixed>  $claims
     */
    private function findOrCreateFromClaims(array $claims): User
    {
        $firebaseUid = (string) $claims['sub'];
        $email = isset($claims['email']) ? (string) $claims['email'] : null;

        $user = User::withTrashed()
            ->where('firebase_uid', $firebaseUid)
            ->when($email !== null, fn ($q) => $q->orWhere('email', $email))
            ->first();

        if ($user === null) {
            $user = new User;
            $user->role = UserRole::default();
            $user->status = UserStatus::Active;
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $user->fill([
            'firebase_uid' => $firebaseUid,
            'email' => $email ?? $user->email,
            'name' => $claims['name'] ?? $user->name ?? 'Listee User',
            'avatar_url' => $claims['picture'] ?? $user->avatar_url,
            'provider' => 'google',
        ]);

        if (! empty($claims['email_verified']) && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }

    /**
     * Mark the login and mint a fresh Sanctum token.
     *
     * @return array{user: User, token: string}
     */
    private function issueSession(User $user): array
    {
        abort_if(! $user->isActive(), 403, 'Your account has been suspended.');

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
