<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Exchange a Google/Firebase ID token for a Sanctum session.
     * POST /api/v1/auth/google (public, throttled).
     */
    public function google(GoogleLoginRequest $request): JsonResponse
    {
        $session = $this->auth->loginWithGoogle($request->string('idToken')->value());

        return ApiResponse::success(
            data: $this->sessionPayload($session),
            message: 'Signed in successfully.',
        );
    }

    /**
     * Mobile/email + PIN sign-in for owners & admins.
     * POST /api/v1/auth/pin-login (public, throttled).
     */
    public function pinLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'pin' => ['required', 'string', 'max:32'],
        ]);

        $session = $this->auth->loginWithPin($validated['identifier'], $validated['pin']);

        return ApiResponse::success(
            data: $this->sessionPayload($session),
            message: 'Signed in successfully.',
        );
    }

    /**
     * Public business-owner sign-up (mobile + PIN).
     * POST /api/v1/auth/register-owner (public, throttled).
     */
    public function registerOwner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'pin' => ['required', 'string', 'min:4', 'max:8', 'regex:/^[0-9]+$/'],
        ], [
            'mobile.unique' => 'An account with this mobile number already exists. Please sign in.',
            'pin.regex' => 'Your PIN must be digits only.',
        ]);

        $session = $this->auth->registerOwner(
            name: $validated['name'],
            phone: $validated['mobile'],
            pin: $validated['pin'],
        );

        return ApiResponse::success(
            data: $this->sessionPayload($session),
            message: 'Account created.',
            status: 201,
        );
    }

    /**
     * The authenticated user. GET /api/v1/auth/me (auth:sanctum).
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: new UserResource($request->user()),
            message: 'Authenticated user.',
        );
    }

    /**
     * Revoke the current access token. POST /api/v1/auth/logout (auth:sanctum).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'Signed out successfully.');
    }

    /**
     * Local-only login shortcut for verifying the app without Firebase creds.
     * POST /api/v1/auth/dev-login — disabled in production.
     */
    public function devLogin(Request $request): JsonResponse
    {
        abort_if(App::environment('production'), 404);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $session = $this->auth->devLogin(
            email: $validated['email'],
            name: $validated['name'] ?? null,
            role: isset($validated['role']) ? UserRole::from($validated['role']) : UserRole::Customer,
        );

        return ApiResponse::success(
            data: $this->sessionPayload($session),
            message: 'Signed in (dev).',
        );
    }

    /**
     * @param  array{user: \App\Models\User, token: string}  $session
     * @return array<string, mixed>
     */
    private function sessionPayload(array $session): array
    {
        return [
            'token' => $session['token'],
            'user' => new UserResource($session['user']),
        ];
    }
}
