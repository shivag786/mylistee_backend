<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Rules\StrongPin;
use App\Services\WhatsApp\WhatsAppOtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forgot PIN, by a code on WhatsApp. Available only while the admin has an
 * approved template set for the login code (GET /config → auth.whatsappOtp).
 */
class PinResetController extends Controller
{
    public function __construct(private readonly WhatsAppOtpService $otp) {}

    /** POST /auth/pin/forgot */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
        $this->otp->send($data['mobile']);

        return ApiResponse::success(message: 'If this number has a Listee account, a code is on its way on WhatsApp.');
    }

    /** POST /auth/pin/reset */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'digits:6'],
            'pin' => ['required', 'string', 'min:4', 'max:8', 'regex:/^[0-9]+$/', new StrongPin()],
        ], [
            'code.digits' => 'Enter the 6-digit code from WhatsApp.',
            'pin.regex' => 'Your PIN must be digits only.',
        ]);

        $this->otp->reset($data['mobile'], $data['code'], $data['pin']);

        return ApiResponse::success(message: 'PIN changed. Sign in with your new PIN.');
    }
}
