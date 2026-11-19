<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\FoodType;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Services\MenuScanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * AI menu scan (owner web panel and vendor app → Menu → Scan menu).
 *
 *   GET  /business/menu-scan          — is it on, scans used / left
 *   POST /business/menu-scan          — files[] (photos or PDF) → dishes to review
 *   POST /business/menu-scan/import   — the reviewed dishes → products
 */
class MenuScanController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly MenuScanService $scanner) {}

    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return ApiResponse::success([
            'available' => $this->scanner->enabled(),
            'used' => $this->scanner->usedThisMonth($business),
            'limit' => $this->scanner->monthlyLimit(),
            'maxFiles' => MenuScanService::MAX_FILES,
        ], 'Menu scan.');
    }

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.MenuScanService::MAX_FILES],
            'files.*' => ['required', 'file', 'max:10240', 'mimetypes:'.implode(',', MenuScanService::MIME_TYPES)],
        ], [
            'files.*.mimetypes' => 'Send photos (JPG, PNG, WEBP, HEIC) or a PDF of the menu.',
            'files.*.max' => 'Each photo must be under 10 MB.',
        ]);
        $business = $this->business($request);

        if (! $this->scanner->enabled()) {
            return ApiResponse::error('Menu scan is switched off right now.', status: 503);
        }

        try {
            $items = $this->scanner->scan($business, array_values($request->file('files')));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 502);
        }

        return ApiResponse::success([
            'items' => $items,
            'used' => $this->scanner->usedThisMonth($business),
            'limit' => $this->scanner->monthlyLimit(),
        ], count($items).' dishes found. Check them before adding.');
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.category' => ['nullable', 'string', 'max:80'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.foodType' => ['nullable', Rule::enum(FoodType::class)],
            'items.*.variants' => ['nullable', 'array', 'max:10'],
            'items.*.variants.*.name' => ['required', 'string', 'max:40'],
            'items.*.variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'skipExisting' => ['sometimes', 'boolean'],
        ]);
        $business = $this->business($request);

        $result = $this->scanner->import($business, $request->user(), $data['items'], (bool) ($data['skipExisting'] ?? true));

        $message = $result['created'].' dishes added to your menu.'
            .($result['skipped'] ? ' '.$result['skipped'].' already on your menu were skipped.' : '')
            .($result['limitReached'] ? ' Your plan’s product limit was reached — upgrade to add the rest.' : '');

        return ApiResponse::success($result, $message, status: $result['created'] > 0 ? 201 : 200);
    }
}
