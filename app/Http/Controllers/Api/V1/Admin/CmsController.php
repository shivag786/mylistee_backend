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

    /** Pages the apps, the website footer and Google Play link to: never deleted. */
    public const CORE = ['privacy', 'terms', 'refund', 'delete-account', 'about', 'contact', 'faq'];

    /** PUT /admin/cms/{slug} — upsert the page content (a new slug makes a new page). */
    public function update(Request $request, string $slug): JsonResponse
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 60) {
            return ApiResponse::error('Use lowercase letters, numbers and dashes for the page link.', ['slug' => ['Use lowercase letters, numbers and dashes.']], 422);
        }

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

    /** DELETE /admin/cms/{slug} — remove a custom page (core pages stay). */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        if (in_array($slug, self::CORE, true)) {
            return ApiResponse::error('This page is linked from the apps and Google Play. Set it to draft instead.', status: 422);
        }
        $page = CmsPage::where('slug', $slug)->first();
        if ($page === null) {
            return ApiResponse::error('Page not found.', status: 404);
        }
        $page->delete();
        $this->audit->log($request->user(), 'cms.delete', $page, "Deleted page {$page->title}");

        return ApiResponse::success(null, 'Page deleted.');
    }
}
