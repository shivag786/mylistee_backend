<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan & subscription management for the Super Admin (document/phase/14
 * §Subscription Management). This is the payoff of Milestone 13 — every limit and
 * price is editable here, no deploy required.
 */
class PlanController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/plans — all plans (including non-public). */
    public function index(): JsonResponse
    {
        $plans = Plan::query()->orderBy('sort_order')->get();

        return ApiResponse::success(PlanResource::collection($plans), 'Plans retrieved.');
    }

    /** PATCH /admin/plans/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $plan = Plan::where('key', $key)->first();
        if ($plan === null) {
            return ApiResponse::error('Plan not found.', status: 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'interval' => ['sometimes', 'in:month,year,lifetime'],
            'maxActiveOffers' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maxOfferDays' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxQrCodes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxGalleryImages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:24'],
            'isPublic' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ]);

        // Map camelCase request keys → snake_case columns.
        $map = [
            'maxActiveOffers' => 'max_active_offers',
            'maxOfferDays' => 'max_offer_days',
            'maxQrCodes' => 'max_qr_codes',
            'maxGalleryImages' => 'max_gallery_images',
            'isPublic' => 'is_public',
            'sortOrder' => 'sort_order',
        ];
        $payload = [];
        foreach ($validated as $field => $value) {
            $payload[$map[$field] ?? $field] = $value;
        }

        $plan->update($payload);
        // Public pricing page is cached — bust it so edits show immediately.
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Controllers\Api\V1\PlanController::CACHE_KEY);
        $this->audit->log($request->user(), 'plan.update', $plan, "Updated plan {$plan->name}", $payload);

        return ApiResponse::success(new PlanResource($plan->fresh()), 'Plan updated.');
    }
}
