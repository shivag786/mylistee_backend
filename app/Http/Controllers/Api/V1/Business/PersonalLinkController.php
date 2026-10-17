<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\PaymentFailedRequest;
use App\Http\Requests\Api\V1\Business\VerifyPaymentRequest;
use App\Services\PersonalLinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Personal link — the owner's switch, the free trial and the day packs.
 * See {@see PersonalLinkService}.
 */
class PersonalLinkController extends Controller
{
    public function __construct(private readonly PersonalLinkService $links) {}

    /** GET /business/personal-link */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        // A payment link an admin sent may have been paid since — the owner
        // lands here straight after paying (it is the link's callback page).
        $this->links->syncPending($business);

        return ApiResponse::success($this->links->state($business->refresh()), 'Personal link retrieved.');
    }

    /** PUT /business/personal-link — switch on (starts the trial once) or off. */
    public function update(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $business = $this->links->setEnabled($business, (bool) $validated['enabled']);
        $state = $this->links->state($business);

        $message = match (true) {
            ! $state['enabled'] => 'Personal link switched off.',
            $state['active'] => "Personal link is live — {$state['daysLeft']} days left.",
            default => 'Choose a pack to activate your personal link.',
        };

        return ApiResponse::success($state, $message);
    }

    /** POST /business/personal-link/checkout — Razorpay order for a pack. */
    public function checkout(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $validated = $request->validate([
            'pack' => ['required', Rule::in(array_keys(PersonalLinkService::PACKS))],
        ]);

        try {
            $checkout = $this->links->checkout($business, $validated['pack'], $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        return ApiResponse::success($checkout, 'Checkout ready.');
    }

    /** POST /business/personal-link/verify — the only way paid days get added. */
    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        try {
            $this->links->verify(
                $business,
                $request->validated('razorpayOrderId'),
                $request->validated('razorpayPaymentId'),
                $request->validated('razorpaySignature'),
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        $state = $this->links->state($business->refresh());

        return ApiResponse::success($state, "Payment successful — your personal link is live for {$state['daysLeft']} days.");
    }

    /** POST /business/personal-link/payment-failed — bookkeeping only. */
    public function failed(PaymentFailedRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $this->links->recordClientFailure(
            $business,
            $request->validated('razorpayOrderId'),
            $request->validated('code'),
            $request->validated('description'),
        );

        return ApiResponse::success(null, 'Payment attempt recorded.');
    }
}
