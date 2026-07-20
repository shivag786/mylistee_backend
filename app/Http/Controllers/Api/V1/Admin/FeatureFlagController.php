<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FeatureFlagResource;
use App\Models\FeatureFlag;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feature flags (document/phase/14 §Feature Flags) — enable/disable features
 * without a deploy.
 */
class FeatureFlagController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/feature-flags */
    public function index(): JsonResponse
    {
        $flags = FeatureFlag::query()->orderBy('name')->get();

        return ApiResponse::success(FeatureFlagResource::collection($flags), 'Feature flags retrieved.');
    }

    /** PATCH /admin/feature-flags/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $flag = FeatureFlag::where('key', $key)->first();
        if ($flag === null) {
            return ApiResponse::error('Feature flag not found.', status: 404);
        }

        $flag->update(['enabled' => $validated['enabled']]);
        $this->audit->log(
            $request->user(),
            'feature_flag.update',
            $flag,
            "{$flag->name}: ".($validated['enabled'] ? 'enabled' : 'disabled'),
        );

        return ApiResponse::success(new FeatureFlagResource($flag), 'Feature flag updated.');
    }
}
