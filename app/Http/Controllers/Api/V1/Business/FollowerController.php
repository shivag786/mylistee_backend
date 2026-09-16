<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customers following the signed-in owner's shop.
 *
 * Followers are the same rows as customer favourites, read from the shop's side
 * -- one saved-shop relationship rather than two parallel ones. Only the
 * business's own owner can read this list.
 */
class FollowerController extends Controller
{
    /** GET /business/followers?page=1 */
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $page = Favorite::query()
            ->where('business_id', $business->id)
            // A follower whose account was deleted leaves an orphan row; joining
            // through the relation keeps those out of the list and the count.
            ->whereHas('customer')
            ->with('customer')
            ->latest('id')
            ->paginate(min((int) $request->integer('perPage', 20), 100));

        $followers = collect($page->items())->map(fn (Favorite $f) => [
            'id' => $f->uuid,
            'name' => $f->customer->name,
            'avatarUrl' => $f->customer->avatar_url ?? null,
            // No email or phone: the owner needs to see who follows them, not a
            // contact list they could export.
            'followedAt' => $f->created_at?->toIso8601String(),
        ])->all();

        return ApiResponse::success(
            [
                'followers' => $followers,
                'total' => $page->total(),
            ],
            'Followers retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
