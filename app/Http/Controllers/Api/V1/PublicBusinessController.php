<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessListItemResource;
use App\Http\Resources\PublicBusinessResource;
use App\Models\Business;
use App\Services\BusinessDiscoveryService;
use App\Services\SpinnerService;
use App\Services\VisitService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public business profile opened from a QR scan (document/phase/02 §QR Code
 * Rules, phase/11 GET /business/{slug}). Works logged-out (shows profile, spin
 * locked); when authenticated it reports whether today's spin is still available.
 */
class PublicBusinessController extends Controller
{
    public function __construct(
        private readonly SpinnerService $spinner,
        private readonly BusinessDiscoveryService $discovery,
        private readonly VisitService $visits,
    ) {}

    /** GET /businesses — discovery list (search / category / sort / nearby). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'category', 'city', 'sort', 'lat', 'lng', 'page', 'perPage', 'verified', 'new', 'withContent']);
        $page = $this->discovery->list($filters, $request->user('sanctum'));

        return ApiResponse::success(
            data: BusinessListItemResource::collection($page->getCollection()),
            message: 'Businesses retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /**
     * Constrain an orderItems sub-query to non-cancelled orders within the
     * "most ordered" window (config/orders.php) — the basis of the Popular badge.
     */
    private function orderedRecently($query)
    {
        return $query->whereHas('order', fn ($o) => $o
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays((int) config('orders.popular.window_days', 90))));
    }

    /** GET /businesses/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)
            ->where('status', BusinessStatus::Active->value)
            ->with([
                'category',
                'gallery',
                'liveOffers',
                // Service layer (Phase 7.6): enabled modes + active dine-in tables.
                'serviceSetting',
                'tables' => fn ($q) => $q->where('status', 'active'),
                // Menu (Phase 7.4): visible products grouped into their sections,
                // with active promotions so effective prices show.
                'productCategories' => fn ($q) => $q->orderBy('position')->orderBy('name'),
                'products' => fn ($q) => $q->where('is_visible', true)
                    ->orderBy('position')->latest('id')->with(['category', 'promotions'])
                    ->withSum(['orderItems as order_count' => fn ($oi) => $this->orderedRecently($oi)], 'quantity'),
                'combos' => fn ($q) => $q->where('is_visible', true)
                    ->orderBy('position')->latest('id')->with('items.product')
                    ->withSum(['orderItems as order_count' => fn ($oi) => $this->orderedRecently($oi)], 'quantity'),
            ])
            ->withCount('followers')
            ->first();

        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        // Optional auth: resolve the customer from a bearer token if one is
        // present, without requiring it (logged-out visitors still see the page).
        $user = $request->user('sanctum');

        // Whether THIS viewer follows the shop, for the Follow button. A
        // logged-out visitor is simply not following, and still sees the count.
        $business->setAttribute(
            'is_following',
            $user !== null && $business->followers()->where('customer_id', $user->id)->exists(),
        );
        $hasOffers = $business->liveOffers->isNotEmpty();

        // Count the profile open as a visit (document/phase/02 §Customer Visit).
        // Best-effort: analytics logging must never break the page load.
        try {
            $this->visits->record($business, $user, $request);
        } catch (\Throwable) {
            // swallow — visit tracking is non-critical
        }

        return ApiResponse::success([
            'business' => new PublicBusinessResource($business),
            'spin' => [
                'available' => $hasOffers,
                'requiresLogin' => $user === null,
                'alreadySpunToday' => $user !== null
                    ? $this->spinner->hasSpunToday($user, $business)
                    : null,
            ],
        ], 'Business profile.');
    }
}
