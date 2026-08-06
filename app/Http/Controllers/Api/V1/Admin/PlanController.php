<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Plan & subscription management for the Super Admin (document/phase/14
 * §Subscription Management). This is the payoff of Milestone 13 — every limit and
 * price is editable here, no deploy required.
 */
class PlanController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** camelCase request key → snake_case column, shared by store + update. */
    private const FIELD_MAP = [
        'maxActiveOffers' => 'max_active_offers',
        'maxActiveCombos' => 'max_active_combos',
        'maxActivePromotions' => 'max_active_promotions',
        'maxPushPerMonth' => 'max_push_per_month',
        'maxOfferDays' => 'max_offer_days',
        'maxQrCodes' => 'max_qr_codes',
        'maxGalleryImages' => 'max_gallery_images',
        'isPublic' => 'is_public',
        'sortOrder' => 'sort_order',
    ];

    /** GET /admin/plans — all plans (including non-public). */
    public function index(): JsonResponse
    {
        $plans = Plan::query()->orderBy('sort_order')->get();

        return ApiResponse::success(PlanResource::collection($plans), 'Plans retrieved.');
    }

    /** POST /admin/plans — create a new plan. `key` is immutable afterwards. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:plans,key'],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'interval' => ['required', 'in:month,quarter,year,lifetime'],
            'maxActiveOffers' => ['nullable', 'integer', 'min:0'],
            'maxActiveCombos' => ['nullable', 'integer', 'min:0'],
            'maxActivePromotions' => ['nullable', 'integer', 'min:0'],
            'maxPushPerMonth' => ['nullable', 'integer', 'min:0'],
            'maxOfferDays' => ['nullable', 'integer', 'min:1'],
            'maxQrCodes' => ['nullable', 'integer', 'min:1'],
            'maxGalleryImages' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'badge' => ['nullable', 'string', 'max:24'],
            'isPublic' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ]);

        $plan = Plan::create($this->mapPayload($validated));
        Cache::forget(\App\Http\Controllers\Api\V1\PlanController::CACHE_KEY);
        $this->audit->log($request->user(), 'plan.create', $plan, "Created plan {$plan->name}");

        return ApiResponse::success(new PlanResource($plan), 'Plan created.', status: 201);
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
            'interval' => ['sometimes', 'in:month,quarter,year,lifetime'],
            'maxActiveOffers' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maxActiveCombos' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maxActivePromotions' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maxPushPerMonth' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maxOfferDays' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxQrCodes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxGalleryImages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:24'],
            'isPublic' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ]);

        $payload = $this->mapPayload($validated);
        $plan->update($payload);
        // Public pricing page is cached — bust it so edits show immediately.
        Cache::forget(\App\Http\Controllers\Api\V1\PlanController::CACHE_KEY);
        $this->audit->log($request->user(), 'plan.update', $plan, "Updated plan {$plan->name}", $payload);

        return ApiResponse::success(new PlanResource($plan->fresh()), 'Plan updated.');
    }

    /**
     * Map validated camelCase request keys to snake_case columns.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapPayload(array $validated): array
    {
        $payload = [];
        foreach ($validated as $field => $value) {
            $payload[self::FIELD_MAP[$field] ?? $field] = $value;
        }

        return $payload;
    }
}
