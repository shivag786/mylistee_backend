<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\VerifyPaymentRequest;
use App\Models\AiCreditPack;
use App\Models\Payment;
use App\Services\AiAssistantService;
use App\Services\AiCreditService;
use App\Services\PlanAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI add-on packs for the owner (web panel + vendor app). Owner-only: staff
 * never reach these routes (config/staff_permissions.php).
 *
 *   GET  /business/ai-credits            packs, this month's usage, purchases
 *   POST /business/ai-credits/checkout   {packId} → Razorpay Checkout options
 *   POST /business/ai-credits/verify     {razorpayOrderId, razorpayPaymentId, razorpaySignature}
 */
class AiCreditController extends Controller
{
    use ResolvesBusiness;

    public function __construct(
        private readonly AiCreditService $credits,
        private readonly AiAssistantService $ai,
        private readonly PlanAccessService $access,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $purchases = Payment::query()->where('business_id', $business->id)->where('purpose', AiCreditService::PURPOSE)
            ->where('status', PaymentStatus::Captured)->latest('paid_at')->limit(20)->get()
            ->map(fn (Payment $p) => [
                'id' => $p->uuid,
                'pack' => $p->meta['pack'] ?? 'AI pack',
                'answers' => (int) ($p->meta['answers'] ?? 0),
                'amount' => (float) $p->amount,
                'paidAt' => $p->paid_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'inPlan' => $this->access->allows($business, 'ai_assistant'),
            'usage' => $this->ai->usage($business),
            'resetsOn' => now()->startOfMonth()->addMonth()->toDateString(),
            'packs' => $this->credits->packsForSale(),
            'paymentsLive' => $this->credits->paymentsLive(),
            'purchases' => $purchases,
        ], 'AI packs.');
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(['packId' => ['required', 'string', 'max:64']]);
        $business = $this->business($request);
        if (! $this->access->allows($business, 'ai_assistant')) {
            return ApiResponse::error('The AI assistant is not in your plan. Upgrade your plan first.', status: 403);
        }
        $pack = AiCreditPack::query()->where('uuid', $data['packId'])->first();
        abort_if($pack === null, 404, 'AI pack not found.');

        try {
            return ApiResponse::success($this->credits->checkout($business, $pack, $request->user()), 'Checkout ready.');
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }
    }

    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        $business = $this->business($request);
        $result = $this->credits->verify(
            $business,
            (string) $request->validated('razorpayOrderId'),
            (string) $request->validated('razorpayPaymentId'),
            (string) $request->validated('razorpaySignature'),
        );

        return ApiResponse::success($result + ['usage' => $this->ai->usage($business)],
            $result['credited'] > 0 ? "{$result['credited']} AI answers added." : 'Already added.');
    }
}
