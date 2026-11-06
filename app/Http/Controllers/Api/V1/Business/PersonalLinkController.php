<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Services\PersonalLinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal link — the owner's on/off switch. Free and never expires.
 * See {@see PersonalLinkService}.
 */
class PersonalLinkController extends Controller
{
    public function __construct(private readonly PersonalLinkService $links) {}

    /** GET /business/personal-link */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success($this->links->state($business), 'Personal link retrieved.');
    }

    /** PUT /business/personal-link — switch on or off. */
    public function update(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $business = $this->links->setEnabled($business, (bool) $validated['enabled']);

        return ApiResponse::success(
            $this->links->state($business),
            $business->personal_link_enabled ? 'Personal link is live.' : 'Personal link switched off.',
        );
    }
}
