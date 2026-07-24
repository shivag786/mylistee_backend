<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ImportSource;
use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ImportApplyRequest;
use App\Http\Requests\Api\V1\Admin\ImportPreviewRequest;
use App\Http\Resources\Admin\AdminBusinessResource;
use App\Http\Resources\Admin\BusinessImportLogResource;
use App\Models\Business;
use App\Models\BusinessImportLog;
use App\Services\AuditService;
use App\Services\Import\BusinessImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC-011 — Business Import Engine (ADMIN ONLY; the admin route group enforces
 * role:admin + active). Thin controller: validates, delegates to the import
 * service, returns the standard envelope. Never modifies the existing Business
 * CRUD endpoints.
 */
class BusinessImportController extends Controller
{
    public function __construct(
        private readonly BusinessImportService $import,
        private readonly AuditService $audit,
    ) {}

    /**
     * POST /admin/businesses/import/preview — fetch public details for a URL.
     * Nothing is persisted (SPEC-011: no save before Import is clicked).
     */
    public function preview(ImportPreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->import->preview($request->string('url')->value());
        } catch (ImportException $e) {
            return ApiResponse::error($e->getMessage(), ['reason' => [$e->reason]], $e->status());
        }

        $duplicate = $result['duplicate'];

        return ApiResponse::success([
            'preview' => $result['data']->toArray(),
            'duplicate' => $duplicate
                ? new AdminBusinessResource($duplicate->loadMissing(['owner:id,name,email,phone', 'category:id,name,uuid']))
                : null,
        ], 'Preview ready.');
    }

    /**
     * POST /admin/businesses/import — apply a confirmed import (create/update/ignore).
     */
    public function import(ImportApplyRequest $request): JsonResponse
    {
        $actor = $request->user();
        $source = ImportSource::from($request->string('source')->value() ?: 'google');
        $url = $request->string('url')->value();
        $placeId = $request->input('placeId');
        $mode = $request->string('mode')->value();

        $existing = null;
        if (in_array($mode, ['update', 'ignore'], true)) {
            $existing = Business::where('uuid', $request->string('businessId')->value())->first();
            if ($existing === null) {
                return ApiResponse::error('The selected business no longer exists.', status: 404);
            }
        }

        if ($mode === 'ignore') {
            $this->import->logIgnored($actor, $source, $url, $placeId, $existing);

            return ApiResponse::success(null, 'Import ignored. Nothing was changed.');
        }

        try {
            $result = $this->import->apply(
                actor: $actor,
                source: $source,
                sourceUrl: $url,
                placeId: $placeId,
                existing: $mode === 'update' ? $existing : null,
                fields: (array) $request->input('fields', []),
            );
        } catch (ImportException $e) {
            return ApiResponse::error($e->getMessage(), ['reason' => [$e->reason]], $e->status());
        }

        $business = $result['business'];
        $count = count($result['updatedFields']);

        $this->audit->log(
            $actor,
            'business.import',
            $business,
            "Imported {$business->name} from {$source->label()} ({$result['mode']})",
            ['mode' => $result['mode'], 'fields' => $result['updatedFields'], 'source' => $source->value],
        );

        return ApiResponse::success([
            'business' => new AdminBusinessResource($business->fresh(['owner', 'category'])),
            'mode' => $result['mode'],
            'updatedFields' => $result['updatedFields'],
            'updatedCount' => $count,
        ], "Business imported successfully. {$count} field(s) updated.");
    }

    /**
     * GET /admin/businesses/import/logs — recent import history (SPEC-011 §ADMIN LOG).
     */
    public function logs(Request $request): JsonResponse
    {
        $page = BusinessImportLog::query()
            ->with(['importer:id,name', 'business:id,uuid,name'])
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            BusinessImportLogResource::collection($page->getCollection()),
            'Import logs retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
