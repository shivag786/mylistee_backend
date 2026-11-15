<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public CMS pages — Privacy, Terms, Refund, Delete account, About, Contact…
 * The admin writes them; the website and both apps show them. Play Store
 * and payment providers open these links signed out, so no auth.
 */
class PageController extends Controller
{
    /** GET /pages — published pages, for footers and the apps' "Legal" list. */
    public function index(): JsonResponse
    {
        $pages = CmsPage::query()
            ->where('status', 'published')
            ->orderBy('title')
            ->get(['slug', 'title', 'updated_at'])
            ->map(fn (CmsPage $p) => [
                'slug' => $p->slug,
                'title' => $p->title,
                'updatedAt' => $p->updated_at?->toIso8601String(),
            ]);

        return ApiResponse::success($pages, 'Pages.');
    }

    /** GET /pages/{slug} — one published page (body is Markdown). */
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->where('status', 'published')->first();
        if ($page === null) {
            return ApiResponse::error('Page not found.', status: 404);
        }

        return ApiResponse::success([
            'slug' => $page->slug,
            'title' => $page->title,
            'body' => $page->body,
            'updatedAt' => $page->updated_at?->toIso8601String(),
        ], 'Page.');
    }
}
