<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of personal links: which shops have theirs on. The link is free
 * and never expires, so there is nothing to give or sell.
 */
class PersonalLinkController extends Controller
{
    /** GET /admin/personal-links?search=&status=on|off */
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->trim()->value();

        $page = Business::query()
            ->select(['id', 'uuid', 'name', 'slug', 'owner_name', 'phone', 'email', 'personal_link_enabled'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('owner_name', 'like', "%{$search}%")))
            ->when($status === 'on', fn ($q) => $q->where('personal_link_enabled', true))
            ->when($status === 'off', fn ($q) => $q->where('personal_link_enabled', false))
            // Shops using it first, then by name.
            ->orderByDesc('personal_link_enabled')
            ->orderBy('name')
            ->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn (Business $b) => [
                'id' => $b->uuid,
                'name' => $b->name,
                'slug' => $b->slug,
                'ownerName' => $b->owner_name,
                'phone' => $b->phone,
                'email' => $b->email,
                'enabled' => (bool) $b->personal_link_enabled,
            ])->values(),
            'Personal links retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
