<?php

namespace App\Http\Middleware;

use App\Services\PlanAccessService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner API sections belong to plan modules (config/plan_modules.php). When a
 * shop's plan does not include a module — or its days ran out — the section
 * refuses changes with a clear "upgrade" message. Reads stay open (except
 * read-locked modules like analytics) so the owner still sees what they had.
 */
class EnforcePlanModules
{
    public function __construct(private readonly PlanAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $module = $this->moduleFor($request);
        if ($module === null) {
            return $next($request);
        }

        [$id, $config] = $module;
        $readOnly = $request->isMethodSafe();
        if ($readOnly && empty($config['readLocked'])) {
            return $next($request);
        }

        $business = Auth::guard('sanctum')->user()?->business();
        if ($business === null || $this->access->allows($business, $id)) {
            return $next($request);
        }

        $reason = $this->access->snapshot($business)['modules'][$id]['reason'] ?? 'plan';
        $message = $reason === 'expired'
            ? 'Your plan has ended. Choose a plan to use '.$config['name'].' again.'
            : $config['name'].' is not in your plan. Upgrade to use it.';

        return ApiResponse::error($message, ['plan' => [$message], 'module' => [$id]], 403);
    }

    /** @return array{0: string, 1: array<string, mixed>}|null */
    private function moduleFor(Request $request): ?array
    {
        $uri = $request->route()?->uri() ?? '';
        if (! str_starts_with($uri, 'api/v1/business/')) {
            return null;
        }
        $section = explode('/', substr($uri, strlen('api/v1/business/')))[0];

        foreach (PlanAccessService::modules() as $id => $config) {
            if (in_array($section, $config['paths'] ?? [], true)) {
                return [$id, $config];
            }
        }

        return null;
    }
}
