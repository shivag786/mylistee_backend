<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Services\AiAssistantService;
use App\Services\PlanAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The owner's AI assistant (vendor app). GET tells the app whether it is on
 * for this shop and how many suggestions are left; POST asks for suggestions.
 * Generating is a "change" under the `ai` section, so a plan without the
 * AI assistant module is refused by EnforcePlanModules before it gets here.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiAssistantService $ai,
        private readonly PlanAccessService $access,
    ) {}

    /** GET /business/ai */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        abort_if($business === null, 404, 'Create your shop first.');

        return ApiResponse::success([
            'available' => $this->ai->enabled(),
            'inPlan' => $this->access->allows($business, 'ai_assistant'),
            'used' => $this->ai->usedThisMonth($business),
            'limit' => $this->ai->monthlyLimit(),
            'kinds' => AiAssistantService::KINDS,
        ], 'AI assistant.');
    }

    /** POST /business/ai/generate { kind, input: {...} } */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AiAssistantService::KINDS))],
            'input' => ['sometimes', 'array'],
            'input.*' => ['nullable'],
        ]);
        $business = $request->user()->business();
        abort_if($business === null, 404, 'Create your shop first.');

        if (! $this->ai->enabled()) {
            return ApiResponse::error('The AI assistant is switched off right now.', status: 503);
        }
        if (! $this->access->allows($business, 'ai_assistant')) {
            return ApiResponse::error('The AI assistant is not in your plan. Upgrade to use it.', status: 403);
        }

        try {
            $suggestions = $this->ai->generate($business, $data['kind'], $data['input'] ?? []);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 502);
        }

        return ApiResponse::success([
            'suggestions' => $suggestions,
            'used' => $this->ai->usedThisMonth($business),
            'limit' => $this->ai->monthlyLimit(),
        ], 'Here are some ideas.');
    }
}
