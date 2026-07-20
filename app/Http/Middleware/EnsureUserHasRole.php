<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one or more roles (document/phase/12 §Authorization).
 * Usage: `->middleware('role:business_owner')` or `role:admin,business_owner`.
 * Runs after auth:sanctum, so a user is guaranteed present.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role->value, $roles, true)) {
            return ApiResponse::error(
                message: 'You do not have permission to access this resource.',
                status: 403,
            );
        }

        return $next($request);
    }
}
