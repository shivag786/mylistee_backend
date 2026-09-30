<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\ServiceType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessServiceSettingResource;
use App\Models\BusinessServiceSetting;
use App\Services\ServiceSettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner's service configuration — which fulfilment modes the shop offers
 * (pickup / dine-in / takeaway / delivery) and its flat delivery fee.
 */
class ServiceSettingController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ServiceSettingService $settings) {}

    /** GET /business/service-settings */
    public function show(Request $request): JsonResponse
    {
        $setting = $this->settings->for($this->business($request));

        return ApiResponse::success(new BusinessServiceSettingResource($setting), 'Service settings.');
    }

    /** PUT /business/service-settings */
    public function update(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'modes' => ['required', 'array', 'min:1'],
            'modes.*' => [Rule::in(ServiceType::values())],
            'defaultMode' => ['required', Rule::in(ServiceType::values())],
            'deliveryFee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'paymentMode' => ['sometimes', Rule::in([BusinessServiceSetting::PAYMENT_FULL, BusinessServiceSetting::PAYMENT_PARTIAL])],
            'partialPercent' => [
                'sometimes', 'integer',
                'min:'.BusinessServiceSetting::PARTIAL_MIN,
                'max:'.BusinessServiceSetting::PARTIAL_MAX,
            ],
            'codEnabled' => ['sometimes', 'boolean'],
        ], [
            'partialPercent.min' => 'The advance must be at least '.BusinessServiceSetting::PARTIAL_MIN.'%.',
            'partialPercent.max' => 'Above '.BusinessServiceSetting::PARTIAL_MAX.'% it is a full payment - choose Full instead.',
        ]);

        $setting = $this->settings->update(
            $business,
            $validated['modes'],
            $validated['defaultMode'],
            (float) ($validated['deliveryFee'] ?? 0),
            $validated['paymentMode'] ?? null,
            isset($validated['partialPercent']) ? (int) $validated['partialPercent'] : null,
            isset($validated['codEnabled']) ? (bool) $validated['codEnabled'] : null,
        );

        return ApiResponse::success(new BusinessServiceSettingResource($setting), 'Service settings saved.');
    }
}
