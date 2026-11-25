<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Services\AiAdvisor\AiCoachService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI sales manager bubble (owner web panel + vendor app).
 *
 *   GET  /business/coach?nudge=1   tips, AI usage, and (when the rules allow) one tip to pop up
 *   POST /business/coach/nudge {action: dismiss|open, tipId}
 *
 * No AI tokens are used here; see AiCoachService.
 */
class AiCoachController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly AiCoachService $coach) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->coach->overview($this->business($request), $request->boolean('nudge')),
            'AI sales manager.',
        );
    }

    public function nudge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'open'])],
            'tipId' => ['nullable', 'string', 'max:40'],
        ]);
        $this->coach->record($this->business($request), $data['action'], $data['tipId'] ?? null);

        return ApiResponse::success(null, 'Saved.');
    }
}
