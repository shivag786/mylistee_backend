<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessTableResource;
use App\Models\BusinessTable;
use App\Services\TableService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dining tables for the owner's business. Each table carries a derived QR the
 * customer scans to open the menu pre-bound to that table. Tables are optional.
 */
class TableController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly TableService $tables) {}

    /** GET /business/tables */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $tables = $business->tables()->with('business:id,slug')->get();

        return ApiResponse::success(BusinessTableResource::collection($tables), 'Tables retrieved.');
    }

    /** POST /business/tables */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $table = $this->tables->create($business, $validated['label'], $validated['capacity'] ?? null);
        $table->setRelation('business', $business);

        return ApiResponse::success(new BusinessTableResource($table), 'Table added.', status: 201);
    }

    /** PUT /business/tables/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $table = $this->find($request, $uuid);
        if ($table === null) {
            return ApiResponse::error('Table not found.', status: 404);
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $table = $this->tables->update($table, $validated['label'], $validated['capacity'] ?? null, $validated['status']);
        $table->setRelation('business', $this->business($request));

        return ApiResponse::success(new BusinessTableResource($table), 'Table updated.');
    }

    /** DELETE /business/tables/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $table = $this->find($request, $uuid);
        if ($table === null) {
            return ApiResponse::error('Table not found.', status: 404);
        }

        $this->tables->delete($table);

        return ApiResponse::success(message: 'Table deleted.');
    }

    /** PATCH /business/tables/reorder */
    public function reorder(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        $this->tables->reorder($business, $validated['order']);

        return $this->index($request);
    }

    private function find(Request $request, string $uuid): ?BusinessTable
    {
        return $this->business($request)->tables()->where('uuid', $uuid)->first();
    }
}
