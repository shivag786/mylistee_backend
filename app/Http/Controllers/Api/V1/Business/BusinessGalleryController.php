<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\StoreGalleryImageRequest;
use App\Http\Resources\GalleryImageResource;
use App\Services\BusinessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business gallery images (document/phase/07 §Gallery). Scoped to the owner's
 * own business; a gallery image can only be removed by the business it belongs to.
 */
class BusinessGalleryController extends Controller
{
    public function __construct(private readonly BusinessService $businesses) {}

    /** POST /business/gallery — upload one gallery image. */
    public function store(StoreGalleryImageRequest $request): JsonResponse
    {
        $business = $request->user()->business();

        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $image = $this->businesses->addGalleryImage($business, $request->file('image'));

        return ApiResponse::success(
            data: new GalleryImageResource($image),
            message: 'Image added to gallery.',
            status: 201,
        );
    }

    /** DELETE /business/gallery/{uuid} — remove one of the owner's gallery images. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $business = $request->user()->business();

        $image = $business?->gallery()->where('uuid', $uuid)->first();

        if ($image === null) {
            return ApiResponse::error('Image not found.', status: 404);
        }

        $this->businesses->removeGalleryImage($image);

        return ApiResponse::success(message: 'Image removed.');
    }
}
