<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CmsPageResource;
use App\Models\CmsPage;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS pages — About / Privacy / Terms / FAQ (document/phase/14 §CMS Management).
 */
class CmsController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/cms */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CmsPageResource::collection(CmsPage::query()->orderBy('title')->get()),
            'Pages retrieved.',
        );
    }

    /** GET /admin/cms/{slug} */
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->first();
        if ($page === null) {
            return ApiResponse::error('Page not found.', status: 404);
        }

        return ApiResponse::success(new CmsPageResource($page), 'Page retrieved.');
    }

    /** PUT /admin/cms/{slug} — upsert the page content. */
    public function update(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $page = CmsPage::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $validated['title'],
                'body' => $validated['body'] ?? null,
                'status' => $validated['status'] ?? 'published',
                'updated_by' => $request->user()->id,
            ],
        );

        $this->audit->log($request->user(), 'cms.update', $page, "Updated page {$page->title}");

        return ApiResponse::success(new CmsPageResource($page), 'Page saved.');
    }
}
