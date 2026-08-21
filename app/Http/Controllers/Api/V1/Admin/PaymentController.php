<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\SubscriptionPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin view of gateway payments, and the refund action behind the published
 * refund policy.
 *
 * Refunds are admin-only and always audited: money leaving the account must be
 * attributable to a person and a reason.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $payments,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/payments — every attempt, newest first. */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()
            ->with(['business:id,name', 'plan:id,name', 'invoice:id,number'])
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->trim()->value(), function ($q, $search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('gateway_order_id', 'like', "%{$search}%")
                        ->orWhere('gateway_payment_id', 'like', "%{$search}%")
                        ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            PaymentResource::collection($page->getCollection()),
            'Payments retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'capturedTotal' => (float) Payment::captured()->sum('amount'),
                'refundedTotal' => (float) Payment::sum('refunded_amount'),
            ],
        );
    }

    /**
     * POST /admin/payments/{uuid}/refund — refund all or part of a captured payment.
     *
     * A full refund also ends the subscription immediately; a partial one is a
     * goodwill adjustment and leaves the plan running.
     */
    public function refund(Request $request, string $uuid): JsonResponse
    {
        $payment = Payment::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $payment = $this->payments->refund(
                $payment,
                isset($validated['amount']) ? (float) $validated['amount'] : null,
                $request->user(),
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 502);
        }

        $this->audit->log(
            $request->user(),
            'payment.refund',
            $payment,
            "Refunded {$payment->currency} {$payment->refunded_amount} on {$payment->gateway_payment_id}",
            ['reason' => $validated['reason'] ?? null, 'full' => $payment->status === PaymentStatus::Refunded],
        );

        return ApiResponse::success(
            new PaymentResource($payment->load(['business:id,name', 'plan:id,name', 'invoice:id,number'])),
            'Refund issued.',
        );
    }
}
