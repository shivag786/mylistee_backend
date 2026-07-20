<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification center (document/phase/11 §Notifications). Scoped to the
 * authenticated user.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** GET /notifications — recent notifications + unread count in meta. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->appNotifications()->limit(50)->get();

        return ApiResponse::success(
            data: NotificationResource::collection($items),
            message: 'Notifications.',
            meta: ['unread' => $this->notifications->unreadCount($user)],
        );
    }

    /** GET /notifications/unread-count — badge counter. */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: ['unread' => $this->notifications->unreadCount($request->user())],
            message: 'Unread count.',
        );
    }

    /** PATCH /notifications/read — mark all (or one via `id`) as read. */
    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $id = $request->input('id');

        if ($id !== null) {
            $notification = $user->appNotifications()->where('uuid', $id)->first();
            if ($notification === null) {
                return ApiResponse::error('Notification not found.', status: 404);
            }
            $this->notifications->markRead($notification);
        } else {
            $this->notifications->markAllRead($user);
        }

        return ApiResponse::success(
            data: ['unread' => $this->notifications->unreadCount($user)],
            message: 'Marked as read.',
        );
    }

    /** DELETE /notifications/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $notification = $request->user()->appNotifications()->where('uuid', $uuid)->first();

        if ($notification === null) {
            return ApiResponse::error('Notification not found.', status: 404);
        }

        $notification->delete();

        return ApiResponse::success(message: 'Notification removed.');
    }
}
