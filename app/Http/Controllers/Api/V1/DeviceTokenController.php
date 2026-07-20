<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FCM device-token registration (document/phase/13 §Push). Tokens are tied to
 * the signed-in user so pushes reach all of their devices.
 */
class DeviceTokenController extends Controller
{
    /** POST /notifications/device-token { token, platform? } — register/refresh. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', Rule::in(['web', 'android', 'ios'])],
        ]);

        // A token belongs to one user — claim it for the current user and refresh.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? 'web',
                'last_used_at' => now(),
            ],
        );

        return ApiResponse::success(message: 'Device registered for notifications.');
    }

    /** DELETE /notifications/device-token — unregister (e.g. on logout). */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        $request->user()->deviceTokens()->where('token', $data['token'])->delete();

        return ApiResponse::success(message: 'Device unregistered.');
    }
}
