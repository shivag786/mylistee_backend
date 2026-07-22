<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\PromotionType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PromotionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Grow Sales" — the promotion engine surface for the owner (Phase 7.2b, 07A).
 * One endpoint set for every promotion type; a promotion can target a product
 * (Smart Offer) or the whole business.
 */
class PromotionController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly PromotionService $promotions) {}

    /** GET /business/promotions?product={uuid}&status={status} */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $query = $business->promotions()
            ->with('product')
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('product')->trim()->value(), function ($q, $uuid) use ($business): void {
                $product = $business->products()->where('uuid', $uuid)->first();
                $q->where('product_id', $product?->id ?? 0);
            })
            ->orderByDesc('priority')
            ->latest('id');

        return ApiResponse::success(PromotionResource::collection($query->get()), 'Promotions retrieved.');
    }

    /** POST /business/promotions */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validateData($request);

        $product = $this->resolveProduct($request, $data['product_id'] ?? null);

        $promotion = $this->promotions->create($business, $data, $request->user(), $product);

        return ApiResponse::success(new PromotionResource($promotion->load('product')), 'Promotion created.', status: 201);
    }

    /** PUT /business/promotions/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->find($request, $uuid);
        if ($promotion === null) {
            return ApiResponse::error('Promotion not found.', status: 404);
        }

        $data = $this->validateData($request);
        $promotion = $this->promotions->update($promotion, $data, $request->user());

        return ApiResponse::success(new PromotionResource($promotion->load('product')), 'Promotion updated.');
    }

    /** PATCH /business/promotions/{uuid}/status — pause or resume. */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->find($request, $uuid);
        if ($promotion === null) {
            return ApiResponse::error('Promotion not found.', status: 404);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume'])],
        ]);

        $promotion = $validated['action'] === 'pause'
            ? $this->promotions->pause($promotion)
            : $this->promotions->resume($promotion);

        return ApiResponse::success(new PromotionResource($promotion->load('product')), 'Promotion updated.');
    }

    /** DELETE /business/promotions/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $promotion = $this->find($request, $uuid);
        if ($promotion === null) {
            return ApiResponse::error('Promotion not found.', status: 404);
        }

        $this->promotions->delete($promotion);

        return ApiResponse::success(message: 'Promotion deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'promotion_type' => ['required', Rule::enum(PromotionType::class)],
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'string'],
            'discount_type' => ['nullable', Rule::in(['percentage', 'flat'])],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'buy_qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'get_qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'min_qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'daily_start_time' => ['nullable', 'date_format:H:i'],
            'daily_end_time' => ['nullable', 'date_format:H:i', 'after:daily_start_time'],
            'auto_start' => ['nullable', 'boolean'],
            'auto_stop' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    private function resolveProduct(Request $request, ?string $uuid): ?Product
    {
        if (! $uuid) {
            return null;
        }

        return $this->business($request)->products()->where('uuid', $uuid)->first();
    }

    private function find(Request $request, string $uuid): ?Promotion
    {
        return $this->business($request)->promotions()->where('uuid', $uuid)->first();
    }
}
