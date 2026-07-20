<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\StoreBusinessRequest;
use App\Http\Requests\Api\V1\Business\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Services\BusinessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business owner profile + dashboard (document/phase/07, phase/11 §Business
 * Owner Endpoints). Every action is scoped to the authenticated owner's own
 * business — ownership is enforced by resolving the business from the user.
 */
class BusinessController extends Controller
{
    public function __construct(private readonly BusinessService $businesses) {}

    /** GET /business/profile — the owner's business, or 404 if none yet. */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();

        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $business->load(['category', 'gallery', 'qrCode']);

        return ApiResponse::success(new BusinessResource($business), 'Business profile.');
    }

    /** POST /business — register a business (one per owner in the current plan). */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $owner = $request->user();

        if ($owner->businesses()->exists()) {
            return ApiResponse::error(
                'You already have a business registered.',
                status: 409,
            );
        }

        $business = $this->businesses->register(
            owner: $owner,
            data: $request->businessData(),
            files: array_filter([
                'logo' => $request->file('logo'),
                'cover' => $request->file('cover'),
            ]),
        );

        return ApiResponse::success(
            data: new BusinessResource($business),
            message: 'Business created successfully.',
            status: 201,
        );
    }

    /** PUT /business/profile — update the owner's business. */
    public function update(UpdateBusinessRequest $request): JsonResponse
    {
        $business = $request->user()->business();

        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $business = $this->businesses->update(
            business: $business,
            data: $request->businessData(),
            files: array_filter([
                'logo' => $request->file('logo'),
                'cover' => $request->file('cover'),
            ]),
            editor: $request->user(),
        );

        return ApiResponse::success(new BusinessResource($business), 'Business updated.');
    }

    /** GET /business/dashboard — headline metrics + onboarding + plan. */
    public function dashboard(Request $request): JsonResponse
    {
        $business = $request->user()->business();

        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $data = $this->businesses->dashboard($business);
        $data['business'] = new BusinessResource($data['business']);

        return ApiResponse::success($data, 'Dashboard data.');
    }
}
