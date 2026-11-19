<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /landing/stats — live numbers for the public landing page: how many
 * shops are on Listee, in which cities, in which categories, and how many
 * customers and orders. The page decides which numbers are big enough to show.
 *
 * Cached for 10 minutes on the file store (the default cache is `null` in
 * production).
 */
class LandingStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $stats = Cache::store('file')->remember('landing-stats:v2', now()->addMinutes(10), fn () => $this->compute());

        return ApiResponse::success($stats, 'Landing stats.');
    }

    /** @return array<string, mixed> */
    private function compute(): array
    {
        $live = Business::query()->where('businesses.status', BusinessStatus::Active->value);

        $cities = (clone $live)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city', DB::raw('COUNT(*) as shops'))
            ->groupBy('city')
            ->get()
            // "jhansi " and "Jhansi" are one city.
            ->groupBy(fn ($r) => Str::lower(trim((string) $r->city)))
            ->map(fn ($rows) => [
                'name' => Str::title(trim((string) $rows->first()->city)),
                'shops' => (int) $rows->sum('shops'),
            ])
            ->sortByDesc('shops')
            ->values()
            ->take(12)
            ->all();

        $categories = (clone $live)
            ->join('business_categories', 'business_categories.id', '=', 'businesses.category_id')
            ->select('business_categories.name', DB::raw('COUNT(*) as shops'))
            ->groupBy('business_categories.name')
            ->orderByDesc('shops')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['name' => Str::title((string) $r->name), 'shops' => (int) $r->shops])
            ->all();

        return [
            'shops' => (clone $live)->count(),
            'cities' => $cities,
            'cityCount' => count($cities),
            'categories' => $categories,
            'customers' => User::query()->where('role', 'customer')->count(),
            'orders' => Order::query()->where('status', '!=', 'cancelled')->count(),
        ];
    }
}
