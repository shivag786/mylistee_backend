<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessListItemResource;
use App\Models\Business;
use App\Services\FavoriteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer favorites (document/phase/11 §Favorites). Scoped to the signed-in
 * customer.
 */
class FavoriteController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites) {}

    /** GET /favorites — the customer's favorite businesses. */
    public function index(Request $request): JsonResponse
    {
        $businesses = Business::query()
            ->whereIn('id', $request->user()->favorites()->pluck('business_id'))
            ->with('category')
            ->withCount(['offers as offer_count' => fn ($q) => $q->live()])
            ->get()
            ->each(fn (Business $b) => $b->setAttribute('is_favorite', true));

        return ApiResponse::success(BusinessListItemResource::collection($businesses), 'Favorites.');
    }

    /** POST /favorites { businessSlug } — add a favorite. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'businessSlug' => ['required', 'string', Rule::exists('businesses', 'slug')],
        ]);
        $business = Business::where('slug', $data['businessSlug'])->firstOrFail();

        $this->favorites->add($request->user(), $business);

        return ApiResponse::success(['isFavorite' => true], 'Added to favorites.');
    }

    /** DELETE /favorites/{slug} — remove a favorite. */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)->firstOrFail();
        $this->favorites->remove($request->user(), $business);

        return ApiResponse::success(['isFavorite' => false], 'Removed from favorites.');
    }
}
