<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authenticated-but-inactive accounts (suspended/pending) from reaching
 * protected endpoints (document/phase/02 §Account Status). Runs after
 * auth:sanctum, so a user is guaranteed present.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            return ApiResponse::error(
                message: 'Your account is not active. Please contact support.',
                status: 403,
            );
        }

        return $next($request);
    }
}
