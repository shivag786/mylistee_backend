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
        $filters = $request->only(['search', 'category', 'sort', 'lat', 'lng', 'page', 'perPage', 'verified', 'new']);
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

    /** GET /businesses/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)
            ->where('status', BusinessStatus::Active->value)
            ->with([
                'category',
                'gallery',
                'liveOffers',
                // Menu (Phase 7.4): visible products grouped into their sections,
                // with active promotions so effective prices show.
                'productCategories' => fn ($q) => $q->orderBy('position')->orderBy('name'),
                'products' => fn ($q) => $q->where('is_visible', true)
                    ->orderBy('position')->latest('id')->with(['category', 'promotions']),
                'combos' => fn ($q) => $q->where('is_visible', true)
                    ->orderBy('position')->latest('id')->with('items.product'),
            ])
            ->first();

        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        // Optional auth: resolve the customer from a bearer token if one is
        // present, without requiring it (logged-out visitors still see the page).
        $user = $request->user('sanctum');
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
