<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminBannerResource;
use App\Models\Banner;
use App\Services\AuditService;
use App\Services\ImageStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin management of homepage advertisement banners. Multipart create/update
 * (image upload) uses POST + _method=PUT, matching the category/product admin
 * flows. Banners are soft-deleted.
 */
class BannerController extends Controller
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/banners — all banners, grouped-friendly order. */
    public function index(): JsonResponse
    {
        $banners = Banner::query()->orderBy('placement')->orderBy('position')->orderBy('id')->get();

        return ApiResponse::success(AdminBannerResource::collection($banners), 'Banners retrieved.');
    }

    /** POST /admin/banners */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBanner($request, creating: true);

        $banner = new Banner($this->attributes($request, $data));
        $banner->created_by = $request->user()->id;
        $banner->image_path = $this->images->store($request->file('image'), 'banners');
        $banner->position = (int) ($data['position']
            ?? (Banner::where('placement', $data['placement'])->max('position') + 1));
        $banner->save();

        $this->audit->log($request->user(), 'banner.create', $banner, "Created banner {$banner->title}");

        return ApiResponse::success(new AdminBannerResource($banner), 'Banner created.', status: 201);
    }

    /** PUT /admin/banners/{uuid} (via POST + _method for multipart) */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $banner = Banner::where('uuid', $uuid)->first();
        if ($banner === null) {
            return ApiResponse::error('Banner not found.', status: 404);
        }

        $data = $this->validateBanner($request, creating: false);
        $banner->fill($this->attributes($request, $data));

        if ($request->hasFile('image')) {
            $this->images->delete($banner->image_path);
            $banner->image_path = $this->images->store($request->file('image'), 'banners');
        }
        $banner->save();

        $this->audit->log($request->user(), 'banner.update', $banner, "Updated banner {$banner->title}");

        return ApiResponse::success(new AdminBannerResource($banner->fresh()), 'Banner updated.');
    }

    /** DELETE /admin/banners/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $banner = Banner::where('uuid', $uuid)->first();
        if ($banner === null) {
            return ApiResponse::error('Banner not found.', status: 404);
        }

        $banner->delete();
        $this->audit->log($request->user(), 'banner.delete', $banner, "Deleted banner {$banner->title}");

        return ApiResponse::success(null, 'Banner deleted.');
    }

    /** PATCH /admin/banners/{uuid}/toggle — flip active on/off. */
    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $banner = Banner::where('uuid', $uuid)->first();
        if ($banner === null) {
            return ApiResponse::error('Banner not found.', status: 404);
        }

        $banner->update(['is_active' => ! $banner->is_active]);
        $this->audit->log($request->user(), 'banner.toggle', $banner, 'Active: '.($banner->is_active ? 'on' : 'off'));

        return ApiResponse::success(new AdminBannerResource($banner->fresh()), 'Banner updated.');
    }

    /** PATCH /admin/banners/reorder — persist positions within a placement. */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['string'],
        ]);

        foreach ($validated['order'] as $index => $uuid) {
            Banner::where('uuid', $uuid)->update(['position' => $index]);
        }

        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBanner(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'image' => [$creating ? 'required' : 'nullable', 'image', 'max:4096'],
            'link_url' => ['nullable', 'string', 'max:512', 'url'],
            'placement' => [$creating ? 'required' : 'sometimes', Rule::enum(BannerPlacement::class)],
            'position' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Request $request, array $data): array
    {
        $attrs = array_intersect_key($data, array_flip(['title', 'link_url', 'placement', 'starts_at', 'ends_at']));
        if ($request->has('is_active')) {
            $attrs['is_active'] = $request->boolean('is_active');
        }

        return $attrs;
    }
}
