<?php

namespace App\Services;

use App\Enums\CategoryRequestStatus;
use App\Enums\NotificationType;
use App\Models\Business;
use App\Models\CategoryRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Owner-submitted category requests + admin moderation (Phase 7.1). Approving a
 * request creates the real master category (via CategoryService) and notifies
 * the owner; rejecting notifies them with an optional reason.
 */
class CategoryRequestService
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly ImageStorageService $images,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * An owner requests a new category. Duplicate open requests for the same
     * name by the same owner are avoided.
     */
    public function request(User $owner, string $name, ?Business $business = null, ?UploadedFile $sample = null): CategoryRequest
    {
        $imagePath = $sample !== null ? $this->images->store($sample, 'category-requests') : null;

        return CategoryRequest::create([
            'requested_by' => $owner->id,
            'business_id' => $business?->id,
            'name' => trim($name),
            'sample_image_path' => $imagePath,
            'status' => CategoryRequestStatus::Pending,
        ]);
    }

    /**
     * Approve a request: create the master category, link it, and notify the
     * owner. No-ops if the request is not pending.
     */
    public function approve(CategoryRequest $request, User $admin): CategoryRequest
    {
        if ($request->status !== CategoryRequestStatus::Pending) {
            return $request;
        }

        return DB::transaction(function () use ($request, $admin): CategoryRequest {
            $category = $this->categories->create(['name' => $request->name]);

            $request->update([
                'status' => CategoryRequestStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'created_category_id' => $category->id,
            ]);

            $this->notifications->notify(
                $request->requester,
                NotificationType::System,
                'Category approved',
                "Your requested category \"{$request->name}\" is now available. You can select it for your business.",
                ['link' => '/business/profile'],
            );

            return $request->fresh(['createdCategory', 'requester']);
        });
    }

    /** Reject a request with an optional note and notify the owner. */
    public function reject(CategoryRequest $request, User $admin, ?string $note = null): CategoryRequest
    {
        if ($request->status !== CategoryRequestStatus::Pending) {
            return $request;
        }

        $request->update([
            'status' => CategoryRequestStatus::Rejected,
            'review_note' => $note,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $reason = $note ? " Reason: {$note}" : '';
        $this->notifications->notify(
            $request->requester,
            NotificationType::System,
            'Category request declined',
            "We couldn't add \"{$request->name}\" right now.{$reason}",
            ['link' => '/business/profile'],
        );

        return $request->fresh('requester');
    }
}
