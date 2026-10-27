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

    /** camelCase request key → snake_case column, shared by store + update. */
    private const FIELD_MAP = [
        'maxActiveOffers' => 'max_active_offers',
        'maxActiveCombos' => 'max_active_combos',
        'maxActivePromotions' => 'max_active_promotions',
        'maxPushPerMonth' => 'max_push_per_month',
        'pushReachPerMonth' => 'push_reach_per_month',
        'pushPerCustomerPerMonth' => 'push_per_customer_per_month',
        'customerDetails' => 'customer_details',
        'maxOfferDays' => 'max_offer_days',
        'maxQrCodes' => 'max_qr_codes',
        'maxGalleryImages' => 'max_gallery_images',
        'maxProducts' => 'max_products',
        'durationDays' => 'duration_days',
        'durationMonths' => 'duration_months',
        'introPrice' => 'intro_price',
        'introDays' => 'intro_days',
        'introEndsAt' => 'intro_ends_at',
        'introLabel' => 'intro_label',
        'isPublic' => 'is_public',
        'isActive' => 'is_active',
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
            'pushReachPerMonth' => ['nullable', 'integer', 'min:0'],
            'pushPerCustomerPerMonth' => ['nullable', 'integer', 'min:1'],
            'customerDetails' => ['sometimes', 'in:full,masked'],
            'maxOfferDays' => ['nullable', 'integer', 'min:1'],
            'maxQrCodes' => ['nullable', 'integer', 'min:1'],
            'maxGalleryImages' => ['nullable', 'integer', 'min:0'],
            ...$this->accessRules(false),
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'badge' => ['nullable', 'string', 'max:24'],
            'isPublic' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ]);

        $payload = $this->mapPayload($validated);
        if ($this->setsDuration($payload)) {
            $payload['days_from'] = now();
        }
        $plan = Plan::create($payload);
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
            'pushReachPerMonth' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'pushPerCustomerPerMonth' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'customerDetails' => ['sometimes', 'in:full,masked'],
            'maxOfferDays' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxQrCodes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxGalleryImages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            ...$this->accessRules(true),
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:24'],
            'isPublic' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ]);

        // Every shop without a paid plan falls back to the default one, so it
        // can't be switched off.
        if ($plan->is_default && $request->has('isActive') && ! $request->boolean('isActive')) {
            return ApiResponse::error('The default plan is always active.', status: 422);
        }

        $payload = $this->mapPayload($validated);
        // Free days count from the day they are first set, so turning them on
        // never ends an older shop's free time on the spot.
        if ($this->setsDuration($payload) && $plan->days_from === null) {
            $payload['days_from'] = now();
        }
        $plan->update($payload);
        $this->audit->log($request->user(), 'plan.update', $plan, "Updated plan {$plan->name}", $payload);

        return ApiResponse::success(new PlanResource($plan->fresh()), 'Plan updated.');
    }

    /**
     * Rules for the plan's access fields: duration, product cap, modules and
     * the first-time offer.
     *
     * @return array<string, mixed>
     */
    private function accessRules(bool $partial): array
    {
        $opt = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'durationDays' => [...$opt, 'integer', 'min:1', 'max:3650'],
            'durationMonths' => [...$opt, 'integer', 'min:1', 'max:120'],
            'maxProducts' => [...$opt, 'integer', 'min:0'],
            'modules' => [...$opt, 'array'],
            'modules.*.on' => ['required', 'boolean'],
            'modules.*.days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'introPrice' => [...$opt, 'numeric', 'min:0'],
            'introDays' => [...$opt, 'integer', 'min:1', 'max:3650', 'required_with:introPrice'],
            'introEndsAt' => [...$opt, 'date'],
            'introLabel' => [...$opt, 'string', 'max:60'],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function setsDuration(array $payload): bool
    {
        return ($payload['duration_days'] ?? null) !== null || ($payload['duration_months'] ?? null) !== null;
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
        // A plan lasts days or months, never both: setting one clears the other.
        if (($payload['duration_months'] ?? null) !== null) {
            $payload['duration_days'] = null;
        } elseif (($payload['duration_days'] ?? null) !== null) {
            $payload['duration_months'] = null;
        }
        // Only known modules, stored as {on, days}.
        if (isset($payload['modules']) && is_array($payload['modules'])) {
            $payload['modules'] = collect($payload['modules'])
                ->only(array_keys(\App\Services\PlanAccessService::modules()))
                ->map(fn ($m) => ['on' => (bool) $m['on'], 'days' => isset($m['days']) ? (int) $m['days'] : null])
                ->all();
        }

        return $payload;
    }
}
