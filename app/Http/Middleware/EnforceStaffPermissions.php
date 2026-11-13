<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff reach an owner route only when config/staff_permissions.php lets them:
 * it is in `always`, or in a permission the owner gave them. Everything else
 * (plan & billing, shop profile, settings, staff list…) stays with the owner.
 * Owners pass straight through. Finer checks — which status an order may move
 * to, how much discount — happen in the services.
 */
class EnforceStaffPermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->role !== UserRole::Staff) {
            return $next($request);
        }

        $staff = $user->staffProfile;
        if ($staff === null || ! $staff->active) {
            return ApiResponse::error('Your staff access has been turned off. Please ask the owner.', status: 403);
        }

        $route = (string) $request->route()?->getName();
        if (self::allowed($route, $staff->permissions ?? [])) {
            return $next($request);
        }

        return ApiResponse::error('You don\'t have access to this. Please ask the owner.', ['permission' => ['not_allowed']], 403);
    }

    /** @param  list<string>  $permissions */
    public static function allowed(string $route, array $permissions): bool
    {
        if ($route === '') {
            return false;
        }
        $config = (array) config('staff_permissions', []);
        if (Str::is($config['always'] ?? [], $route)) {
            return true;
        }
        foreach ($permissions as $permission) {
            $routes = $config['permissions'][$permission]['routes'] ?? [];
            if ($routes !== [] && Str::is($routes, $route)) {
                return true;
            }
        }

        return false;
    }
}
