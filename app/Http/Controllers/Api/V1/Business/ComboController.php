<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComboResource;
use App\Models\Combo;
use App\Services\ComboService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Combo builder CRUD for the owner (Phase 7.3). Combos bundle 2–3 of the
 * business's products at a special price.
 */
class ComboController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ComboService $combos) {}

    /** GET /business/combos */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $combos = $business->combos()
            ->with('items.product')
            ->orderBy('position')
            ->latest('id')
            ->get();

        return ApiResponse::success(ComboResource::collection($combos), 'Combos retrieved.');
    }

    /** POST /business/combos */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validateData($request);

        $combo = $this->combos->create($business, $data, $data['items'], $request->file('image'), $request->user());

        return ApiResponse::success(new ComboResource($combo), 'Combo created.', status: 201);
    }

    /** POST /business/combos/{uuid} (with _method=PUT) */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $combo = $this->find($request, $uuid);
        if ($combo === null) {
            return ApiResponse::error('Combo not found.', status: 404);
        }

        $data = $this->validateData($request, required: false);
        $combo = $this->combos->update($combo, $data, $data['items'] ?? null, $request->file('image'), $request->user());

        return ApiResponse::success(new ComboResource($combo), 'Combo updated.');
    }

    /** DELETE /business/combos/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $combo = $this->find($request, $uuid);
        if ($combo === null) {
            return ApiResponse::error('Combo not found.', status: 404);
        }

        $this->combos->delete($combo);

        return ApiResponse::success(message: 'Combo deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $required = true): array
    {
        $itemsRule = $required ? ['required', 'array', 'min:2', 'max:3'] : ['sometimes', 'array', 'min:2', 'max:3'];

        return $request->validate([
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:120'],
            'combo_price' => [$required ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999'],
            'product_category_id' => ['nullable', 'string'],
            'coins_earned' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'wallet_coins_accepted' => ['nullable', 'boolean'],
            'coins_accepted' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'next_visit_coupon' => ['nullable', 'string', 'max:120'],
            'bonus_reward' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'auto_enable' => ['nullable', 'boolean'],
            'auto_disable' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'items' => $itemsRule,
            'items.*.product_id' => ['required_with:items', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
    }

    private function find(Request $request, string $uuid): ?Combo
    {
        return $this->business($request)->combos()->where('uuid', $uuid)->first();
    }
}
