<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOfferResource;
use App\Models\Offer;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Offer oversight for the Super Admin (document/phase/14 §Offer Management).
 * Admin can suspend (archive) an abusive offer; business owners still own CRUD.
 */
class OfferController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/offers */
    public function index(Request $request): JsonResponse
    {
        $query = Offer::query()
            ->with('business:id,name,slug')
            ->when($request->string('search')->trim()->value(), function ($q, $search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            AdminOfferResource::collection($page->getCollection()),
            'Offers retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** PATCH /admin/offers/{uuid}/status — active / archived (suspend). */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([OfferStatus::Active->value, OfferStatus::Archived->value])],
        ]);

        $offer = Offer::where('uuid', $uuid)->first();
        if ($offer === null) {
            return ApiResponse::error('Offer not found.', status: 404);
        }

        $offer->update(['status' => $validated['status']]);
        $this->audit->log($request->user(), 'offer.status', $offer, "Set status to {$validated['status']}");

        return ApiResponse::success(new AdminOfferResource($offer->fresh('business')), 'Offer updated.');
    }
}
