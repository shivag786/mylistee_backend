<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\RewardRule;
use App\Services\PlatformFeeService;
use App\Services\RewardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner's "Rewards & coupons" screen: three automatic rewards set up once,
 * plus the shop's coupon codes. (Personal coupons are minted by the system.)
 */
class RewardController extends Controller
{
    use ResolvesBusiness;

    public function __construct(
        private readonly RewardService $rewards,
        private readonly PlatformFeeService $fees,
    ) {}

    /** GET /business/rewards */
    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $setting = $this->rewards->settingsFor($business);

        return ApiResponse::success([
            'billDiscount' => $setting->bill_discount,
            'comeback' => $setting->comeback,
            // Always the steps shape, whatever the row was saved as.
            'loyalty' => $this->rewards->loyaltySteps($setting->loyalty),
            'coupons' => Coupon::where('business_id', $business->id)
                ->whereNull('customer_id')
                ->latest('id')
                ->get()
                ->map(fn (Coupon $c) => $this->couponRow($c)),
            // Earned by customers and how many were used — the "is it working?" line.
            'stats' => [
                'personalIssued' => Coupon::where('business_id', $business->id)->whereNotNull('customer_id')->count(),
                'personalUsed' => Coupon::where('business_id', $business->id)->whereNotNull('customer_id')->where('used_count', '>', 0)->count(),
            ],
            'fees' => ['platformPercent' => $this->fees->platformPercent(), 'gatewayPercent' => $this->fees->gatewayPercent()],
        ], 'Rewards.');
    }

    /** PUT /business/rewards */
    public function update(Request $request): JsonResponse
    {
        // A client from before loyalty steps sends one `{every, type, value}`
        // rule; that is a single repeating step.
        $loyalty = $request->input('loyalty');
        if (is_array($loyalty) && ! isset($loyalty['steps']) && isset($loyalty['every'])) {
            $request->merge(['loyalty' => $this->rewards->loyaltySteps($loyalty)]);
        }

        $rule = fn (string $key) => [
            $key => ['present', 'nullable', 'array'],
            "{$key}.type" => ['required_with:'.$key, Rule::in(['percent', 'flat'])],
            "{$key}.value" => ['required_with:'.$key, 'numeric', 'min:0', 'max:100000'],
            "{$key}.max_off" => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
        $data = $request->validate(array_merge(
            $rule('bill_discount'),
            ['bill_discount.min_bill' => ['required_with:bill_discount', 'numeric', 'min:0', 'max:1000000']],
            $rule('comeback'),
            ['comeback.min_bill' => ['nullable', 'numeric', 'min:0'], 'comeback.days' => ['required_with:comeback', 'integer', 'min:1', 'max:90']],
            // Loyalty is a card of steps — a reward on the 3rd, 5th, 6th… order.
            [
                'loyalty' => ['present', 'nullable', 'array'],
                'loyalty.repeat' => ['nullable', 'boolean'],
                'loyalty.steps' => ['required_with:loyalty', 'array', 'min:1', 'max:'.RewardService::MAX_STEPS],
                'loyalty.steps.*.at' => ['required', 'integer', 'min:1', 'max:'.RewardService::MAX_STEP_AT, 'distinct'],
                'loyalty.steps.*.type' => ['required', Rule::in(['percent', 'flat'])],
                'loyalty.steps.*.value' => ['required', 'numeric', 'min:1', 'max:100000'],
                'loyalty.steps.*.max_off' => ['nullable', 'numeric', 'min:0', 'max:100000'],
                'loyalty.steps.*.min_bill' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ],
        ), [
            'loyalty.steps.*.at.distinct' => 'Each loyalty step needs its own order number.',
            'loyalty.steps.*.value.min' => 'Give every loyalty step a reward.',
        ]);
        $percents = [$data['bill_discount'] ?? null, $data['comeback'] ?? null, ...($data['loyalty']['steps'] ?? [])];
        foreach ($percents as $rule) {
            if (($rule['type'] ?? null) === 'percent' && (float) $rule['value'] > 90) {
                return ApiResponse::error('Keep a percentage discount at 90% or less.', status: 422);
            }
        }

        $this->rewards->update($this->business($request), $data);

        return $this->show($request);
    }

    /** GET /business/rewards/customers — who comes back, and how far along the card they are. */
    public function customers(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rewards->repeatCustomers($this->business($request)), 'Repeat customers.');
    }

    /** POST /business/coupons */
    public function storeCoupon(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validateCoupon($request, $business);

        $coupon = Coupon::create($data + ['business_id' => $business->id, 'kind' => Coupon::KIND_CODE]);

        return ApiResponse::success($this->couponRow($coupon), 'Coupon created.', status: 201);
    }

    /** PUT /business/coupons/{uuid} */
    public function updateCoupon(Request $request, string $uuid): JsonResponse
    {
        $business = $this->business($request);
        $coupon = $this->findCoupon($business, $uuid);
        if ($coupon === null) {
            return ApiResponse::error('Coupon not found.', status: 404);
        }

        $coupon->update($this->validateCoupon($request, $business, $coupon));

        return ApiResponse::success($this->couponRow($coupon->fresh()), 'Coupon updated.');
    }

    /** DELETE /business/coupons/{uuid} */
    public function destroyCoupon(Request $request, string $uuid): JsonResponse
    {
        $coupon = $this->findCoupon($this->business($request), $uuid);
        if ($coupon === null) {
            return ApiResponse::error('Coupon not found.', status: 404);
        }
        $coupon->delete();

        return ApiResponse::success(null, 'Coupon deleted.');
    }

    /** @return array<string, mixed> */
    private function validateCoupon(Request $request, Business $business, ?Coupon $existing = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => [
                'required', 'string', 'min:3', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('coupons', 'code')->where('business_id', $business->id)->ignore($existing?->id),
            ],
            'title' => ['nullable', 'string', 'max:80'],
            'discountType' => ['required', Rule::in(['percent', 'flat'])],
            'value' => ['required', 'numeric', 'min:1', 'max:100000'],
            'maxDiscount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'minBill' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'perCustomerLimit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'usageLimit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expiresAt' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ], ['code.regex' => 'Use only letters and numbers, e.g. WELCOME20.']);

        if ($data['discountType'] === 'percent' && (float) $data['value'] > 90) {
            abort(response()->json(['success' => false, 'message' => 'Keep a percentage discount at 90% or less.'], 422));
        }

        return [
            'code' => $data['code'],
            'title' => $data['title'] ?? RewardRule::label($data['discountType'], (float) $data['value'], isset($data['maxDiscount']) ? (float) $data['maxDiscount'] : null),
            'discount_type' => $data['discountType'],
            'value' => $data['value'],
            'max_discount' => $data['maxDiscount'] ?? null,
            'min_bill' => $data['minBill'] ?? null,
            'per_customer_limit' => $data['perCustomerLimit'] ?? 1,
            'usage_limit' => $data['usageLimit'] ?? null,
            'expires_at' => isset($data['expiresAt']) ? \Illuminate\Support\Carbon::parse($data['expiresAt'])->endOfDay() : null,
            'active' => $data['active'] ?? true,
        ];
    }

    /** @return array<string, mixed> */
    private function couponRow(Coupon $c): array
    {
        return [
            'id' => $c->uuid,
            'code' => $c->code,
            'title' => $c->title,
            'label' => $c->label(),
            'discountType' => $c->discount_type,
            'value' => (float) $c->value,
            'maxDiscount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'minBill' => $c->min_bill !== null ? (float) $c->min_bill : null,
            'perCustomerLimit' => $c->per_customer_limit,
            'usageLimit' => $c->usage_limit,
            'usedCount' => $c->used_count,
            'expiresAt' => $c->expires_at?->toIso8601String(),
            'active' => $c->active,
            'live' => $c->isLive(),
        ];
    }

    private function findCoupon(Business $business, string $uuid): ?Coupon
    {
        return Coupon::where('business_id', $business->id)->whereNull('customer_id')->where('uuid', $uuid)->first();
    }

}
