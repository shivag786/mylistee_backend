<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\StoreOfferRequest;
use App\Http\Requests\Api\V1\Business\UpdateOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Services\OfferService;
use App\Services\OfferSuggestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Offer CRUD for the business owner (document/phase/11 §Offer Endpoints).
 * Every action is scoped to the owner's own business.
 */
class OfferController extends Controller
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OfferSuggestionService $suggestions,
    ) {}

    /** GET /business/offers/suggestions — offer ideas (templates + analytics + AI). */
    public function suggestions(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success($this->suggestions->forBusiness($business), 'Offer suggestions.');
    }

    /** GET /business/offers */
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $offers = $business->offers()
            ->orderByDesc('priority')
            ->latest()
            ->get();

        return ApiResponse::success(OfferResource::collection($offers), 'Offers retrieved.');
    }

    /** POST /business/offers */
    public function store(StoreOfferRequest $request): JsonResponse
    {
        $business = $request->user()->business();
        if ($business === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $offer = $this->offers->create(
            business: $business,
            data: $request->offerData(),
            image: $request->file('image'),
            author: $request->user(),
        );

        return ApiResponse::success(new OfferResource($offer), 'Offer created.', status: 201);
    }

    /** PUT /business/offers/{uuid} */
    public function update(UpdateOfferRequest $request, string $uuid): JsonResponse
    {
        $offer = $this->resolveOwnedOffer($request, $uuid);
        if ($offer === null) {
            return ApiResponse::error('Offer not found.', status: 404);
        }

        $offer = $this->offers->update(
            offer: $offer,
            data: $request->offerData(),
            image: $request->file('image'),
            editor: $request->user(),
        );

        return ApiResponse::success(new OfferResource($offer), 'Offer updated.');
    }

    /** DELETE /business/offers/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $offer = $this->resolveOwnedOffer($request, $uuid);
        if ($offer === null) {
            return ApiResponse::error('Offer not found.', status: 404);
        }

        $this->offers->delete($offer);

        return ApiResponse::success(message: 'Offer deleted.');
    }

    /** PATCH /business/offers/{uuid}/status — flip active/archived. */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OfferStatus::class)],
        ]);

        $offer = $this->resolveOwnedOffer($request, $uuid);
        if ($offer === null) {
            return ApiResponse::error('Offer not found.', status: 404);
        }

        $offer = $this->offers->setStatus($offer, OfferStatus::from($validated['status']));

        return ApiResponse::success(new OfferResource($offer), 'Offer status updated.');
    }

    private function resolveOwnedOffer(Request $request, string $uuid): ?Offer
    {
        $business = $request->user()->business();

        return $business?->offers()->where('uuid', $uuid)->first();
    }
}
