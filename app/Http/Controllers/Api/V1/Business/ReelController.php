<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShopReelResource;
use App\Models\ShopReel;
use App\Services\ImageStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owner's Shop Reels: a short video (with a poster frame the browser cuts
 * from it), or 2–6 photos the app plays as a reel. Videos are stored as sent —
 * the server has no transcoder — so size and length are capped here and in the
 * upload form.
 */
class ReelController extends Controller
{
    use ResolvesBusiness;

    /** Per-shop cap, so the feed stays fresh and storage stays bounded. */
    private const MAX_REELS = 30;

    public function __construct(private readonly ImageStorageService $images) {}

    /** GET /business/reels */
    public function index(Request $request): JsonResponse
    {
        $reels = ShopReel::where('business_id', $this->business($request)->id)
            ->with('product.promotions')
            ->latest('id')
            ->get();

        return ApiResponse::success(ShopReelResource::collection($reels), 'Reels retrieved.');
    }

    /** POST /business/reels (multipart) */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(['video', 'photos'])],
            'video' => ['required_if:type,video', 'nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:25600'],
            'poster' => ['nullable', 'image', 'max:4096'],
            'photos' => ['required_if:type,photos', 'nullable', 'array', 'min:2', 'max:6'],
            'photos.*' => ['image', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:150'],
            'productId' => ['nullable', 'string'],
            'durationSeconds' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], [
            'video.max' => 'The video must be 25 MB or smaller.',
            'photos.min' => 'Add at least 2 photos.',
            'photos.max' => 'Use up to 6 photos.',
        ]);

        if (ShopReel::where('business_id', $business->id)->count() >= self::MAX_REELS) {
            throw ValidationException::withMessages([
                'type' => ['You have '.self::MAX_REELS.' reels already. Delete an old one to add a new one.'],
            ]);
        }

        $productId = null;
        if (! empty($data['productId'])) {
            $productId = $business->products()->where('uuid', $data['productId'])->value('id');
            if ($productId === null) {
                throw ValidationException::withMessages(['productId' => ['That dish is not on your menu.']]);
            }
        }

        $reel = new ShopReel([
            'business_id' => $business->id,
            'type' => $data['type'],
            'caption' => filled($data['caption'] ?? null) ? trim($data['caption']) : null,
            'product_id' => $productId,
            'duration_seconds' => $data['durationSeconds'] ?? null,
            'is_visible' => true,
        ]);

        if ($data['type'] === 'video') {
            $reel->video_path = $request->file('video')->store('reels/videos', 'public');
            if ($request->hasFile('poster')) {
                $reel->poster_path = $this->images->store($request->file('poster'), 'reels/posters');
            }
        } else {
            $reel->photo_paths = array_map(
                fn ($photo) => $this->images->store($photo, 'reels/photos'),
                $request->file('photos'),
            );
        }

        $reel->save();
        $reel->load('product.promotions');

        return ApiResponse::success(new ShopReelResource($reel), 'Reel posted.', status: 201);
    }

    /** PATCH /business/reels/{uuid}/visibility { visible: bool } */
    public function visibility(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['visible' => ['required', 'boolean']]);
        $reel = $this->find($request, $uuid);
        if ($reel === null) {
            return ApiResponse::error('Reel not found.', status: 404);
        }

        $reel->update(['is_visible' => (bool) $validated['visible']]);
        $reel->load('product.promotions');

        return ApiResponse::success(new ShopReelResource($reel), $reel->is_visible ? 'Reel is live.' : 'Reel hidden.');
    }

    /** DELETE /business/reels/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $reel = $this->find($request, $uuid);
        if ($reel === null) {
            return ApiResponse::error('Reel not found.', status: 404);
        }

        foreach ([$reel->video_path, $reel->poster_path, ...($reel->photo_paths ?? [])] as $path) {
            $this->images->delete($path);
        }
        $reel->delete();

        return ApiResponse::success(message: 'Reel deleted.');
    }

    private function find(Request $request, string $uuid): ?ShopReel
    {
        return ShopReel::where('uuid', $uuid)
            ->where('business_id', $this->business($request)->id)
            ->first();
    }
}
