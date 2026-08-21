<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\PaymentFailedRequest;
use App\Http\Requests\Api\V1\Business\VerifyPaymentRequest;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Paying for a business plan with Razorpay.
 *
 * Split out from {@see SubscriptionController} on purpose: that controller
 * handles plan *state* (what you are on, what it costs, downgrades), this one
 * handles *money*. The two endpoints here bracket the hosted Checkout modal —
 * `checkout` opens it, `verify` is the only thing that can turn a payment into
 * an active plan.
 */
class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $payments,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * POST /business/subscription/checkout — create a Razorpay order.
     *
     * Returns the publishable key alongside the order, so the frontend needs no
     * gateway config of its own and a key rotation is a server-side change.
     */
    public function checkout(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $validated = $request->validate([
            'planKey' => ['required', 'string', 'exists:plans,key'],
        ]);

        $plan = Plan::where('key', $validated['planKey'])->firstOrFail();

        if (! $plan->is_public) {
            return ApiResponse::error('That plan is not available.', status: 422);
        }

        try {
            $checkout = $this->payments->checkout($business, $plan, $request->user());
        } catch (RuntimeException $e) {
            // Gateway down or unconfigured — a 503 tells the client to retry
            // later rather than treating it as a bad request.
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        return ApiResponse::success($checkout, 'Checkout ready.');
    }

    /**
     * POST /business/subscription/verify — verify the Checkout handshake and
     * activate the plan.
     *
     * Safe to call twice: a payment that is already captured returns the current
     * state instead of activating again.
     */
    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        try {
            $result = $this->payments->verify(
                $business,
                $request->validated('razorpayOrderId'),
                $request->validated('razorpayPaymentId'),
                $request->validated('razorpaySignature'),
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        $plan = $result['payment']->plan;

        return ApiResponse::success(
            $this->present($business),
            $plan !== null ? "Payment successful — you're now on {$plan->name}." : 'Payment successful.',
        );
    }

    /**
     * POST /business/subscription/payment-failed — log an attempt the browser
     * reported as failed, so an owner who says "it did not work" leaves a trace.
     */
    public function failed(PaymentFailedRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $this->payments->recordClientFailure($business, $request->validated('razorpayOrderId'), [
            'code' => $request->validated('code'),
            'description' => $request->validated('description'),
        ]);

        return ApiResponse::success(null, 'Payment attempt recorded.');
    }

    /**
     * The canonical subscription payload, identical to
     * {@see SubscriptionController::present()} so the client refreshes the same
     * shape after paying as it does on load.
     *
     * @return array<string, mixed>
     */
    private function present($business): array
    {
        $state = $this->subscriptions->state($business);

        return [
            'plan' => $state['plan'] ? new PlanResource($state['plan']) : null,
            'subscription' => $state['subscription']
                ? new SubscriptionResource($state['subscription']->loadMissing('plan'))
                : null,
            'usage' => $state['usage'],
        ];
    }
}
