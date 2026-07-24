<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminBusinessResource;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Services\AuditService;
use App\Services\ImageStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Business management for the Super Admin (document/phase/14 §Business
 * Management). Businesses are never hard-deleted — only status changes.
 */
class BusinessController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ImageStorageService $images,
    ) {}

    /** GET /admin/businesses */
    public function index(Request $request): JsonResponse
    {
        $query = Business::query()
            ->with(['owner:id,name,email,phone,pin_plain', 'category:id,name'])
            ->when($request->string('search')->trim()->value(), function ($q, $search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('verified') || $request->query('verified') === 'false', function ($q) use ($request): void {
                $q->where('verified', $request->boolean('verified'));
            });

        $sort = $request->string('sort')->value() ?: 'newest';
        match ($sort) {
            'name' => $query->orderBy('name'),
            'rating' => $query->orderByDesc('average_rating'),
            default => $query->latest('id'),
        };

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            AdminBusinessResource::collection($page->getCollection()),
            'Businesses retrieved.',
            meta: $this->pageMeta($page),
        );
    }

    /** GET /admin/businesses/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $business = Business::with(['owner:id,name,email', 'category:id,name'])
            ->where('uuid', $uuid)->first();

        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        return ApiResponse::success(new AdminBusinessResource($business), 'Business retrieved.');
    }

    /** PATCH /admin/businesses/{uuid}/status */
    /** PUT /admin/businesses/{uuid} — edit business information. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $business = Business::where('uuid', $uuid)->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'string'], // category uuid
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'string', 'max:200'],
            'facebook' => ['nullable', 'string', 'max:200'],
            'instagram' => ['nullable', 'string', 'max:200'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'gst' => ['nullable', 'string', 'max:40'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', Rule::enum(BusinessStatus::class)],
        ]);

        $attributes = $validated;
        if (array_key_exists('category_id', $attributes)) {
            $attributes['category_id'] = $attributes['category_id']
                ? BusinessCategory::where('uuid', $attributes['category_id'])->value('id')
                : null;
        }

        $business->update($attributes);
        $this->audit->log($request->user(), 'business.update', $business, "Updated business {$business->name}");

        return ApiResponse::success(
            new AdminBusinessResource($business->fresh(['owner', 'category'])),
            'Business updated.',
        );
    }

    /** POST /admin/businesses/{uuid}/image — upload/replace the logo or banner. */
    public function uploadImage(Request $request, string $uuid): JsonResponse
    {
        $business = Business::where('uuid', $uuid)->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['logo', 'cover'])],
            'image' => ['required', 'image', 'max:4096'],
        ]);

        $file = $request->file('image');
        if ($validated['type'] === 'logo') {
            $this->images->delete($business->logo_path);
            $business->logo_path = $this->images->store($file, 'businesses/logos');
        } else {
            $this->images->delete($business->cover_path);
            $business->cover_path = $this->images->store($file, 'businesses/covers');
        }
        $business->save();
        $this->audit->log($request->user(), 'business.image', $business, "Updated {$validated['type']} for {$business->name}");

        return ApiResponse::success(
            new AdminBusinessResource($business->fresh(['owner', 'category'])),
            'Image updated.',
        );
    }

    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(BusinessStatus::class)],
        ]);

        $business = Business::where('uuid', $uuid)->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $business->update(['status' => $validated['status']]);
        $this->audit->log(
            $request->user(),
            'business.status',
            $business,
            "Set status to {$validated['status']}",
            ['status' => $validated['status']],
        );

        return ApiResponse::success(new AdminBusinessResource($business->fresh(['owner', 'category'])), 'Business updated.');
    }

    /** PATCH /admin/businesses/{uuid}/verify — toggle verified. */
    public function verify(Request $request, string $uuid): JsonResponse
    {
        return $this->toggle($request, $uuid, 'verified', 'business.verify');
    }

    /** PATCH /admin/businesses/{uuid}/feature — toggle featured. */
    public function feature(Request $request, string $uuid): JsonResponse
    {
        return $this->toggle($request, $uuid, 'featured', 'business.feature');
    }

    private function toggle(Request $request, string $uuid, string $column, string $action): JsonResponse
    {
        $business = Business::where('uuid', $uuid)->first();
        if ($business === null) {
            return ApiResponse::error('Business not found.', status: 404);
        }

        $business->update([$column => ! $business->{$column}]);
        $this->audit->log($request->user(), $action, $business, ucfirst($column).': '.($business->{$column} ? 'on' : 'off'));

        return ApiResponse::success(new AdminBusinessResource($business->fresh(['owner', 'category'])), 'Business updated.');
    }

    /**
     * @param  \Illuminate\Pagination\LengthAwarePaginator<int, Business>  $page
     * @return array<string, int>
     */
    private function pageMeta($page): array
    {
        return [
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
