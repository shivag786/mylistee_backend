<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\SubscribeRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Services\RazorpayService;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business owner subscription & billing (document/phase/14 §Subscription /
 * Payment Management). Plan *state* lives here — what you are on, what it costs,
 * downgrades and cancellation. Taking money lives in
 * {@see SubscriptionPaymentController}. All actions are scoped to the owner's
 * own business.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly RazorpayService $razorpay,
    ) {}

    /** GET /business/subscription — current plan, subscription and usage. */
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success($this->present($business), 'Subscription retrieved.');
    }

    /**
     * POST /business/subscription — switch plan.
     *
     * Downgrades to the free plan settle here. A *paid* plan does not: once
     * Razorpay is configured this endpoint refuses it and the client must go
     * through /subscription/checkout, so a plan can never go live without money
     * having actually moved. With no gateway configured (local dev, demo seed)
     * the pre-gateway simulated upgrade still works, matching how the optional
     * Anthropic/Google integrations degrade elsewhere in the app.
     */
    public function store(SubscribeRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $plan = Plan::where('key', $request->validated('planKey'))->firstOrFail();

        if (! $plan->isFree() && ! $plan->is_default && $this->razorpay->isConfigured()) {
            return ApiResponse::error(
                'This plan must be paid for. Start the payment from the plans screen.',
                status: 422,
            );
        }

        $this->subscriptions->subscribe($business, $plan, $request->user());

        return ApiResponse::success(
            $this->present($business),
            $plan->isFree() ? 'Switched to the Free plan.' : "You're now on {$plan->name}.",
        );
    }

    /** POST /business/subscription/cancel — cancel; access lasts until period end. */
    public function cancel(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $this->subscriptions->cancel($business);

        return ApiResponse::success($this->present($business), 'Subscription cancelled.');
    }

    /** GET /business/invoices */
    public function invoices(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success(
            InvoiceResource::collection($this->subscriptions->invoicesFor($business)),
            'Invoices retrieved.',
        );
    }

    /**
     * The canonical subscription payload (plan + subscription + usage).
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
