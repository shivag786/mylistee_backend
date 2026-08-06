<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\ServiceType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessServiceSettingResource;
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
        ]);

        $setting = $this->settings->update(
            $business,
            $validated['modes'],
            $validated['defaultMode'],
            (float) ($validated['deliveryFee'] ?? 0),
        );

        return ApiResponse::success(new BusinessServiceSettingResource($setting), 'Service settings saved.');
    }
}
