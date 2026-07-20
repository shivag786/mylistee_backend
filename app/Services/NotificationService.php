<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

/**
 * Creates + delivers notifications (document/phase/07 §Notifications, phase/13
 * §Push). The in-app record is always persisted (source of truth); a push is a
 * best-effort layer sent via FCM when configured. Invalid device tokens are
 * pruned automatically.
 */
class NotificationService
{
    public function __construct(private readonly FirebaseService $firebase) {}

    /**
     * Persist a notification for a user and attempt a push.
     *
     * @param  array<string, mixed>  $data  deep-link payload, e.g. ['link' => '/wallet']
     */
    public function notify(User $user, NotificationType $type, string $title, ?string $body = null, array $data = []): Notification
    {
        $notification = $user->appNotifications()->create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);

        $this->push($user, $title, $body ?? '', $data);

        return $notification;
    }

    /**
     * Best-effort FCM push to all of the user's devices.
     *
     * @param  array<string, mixed>  $data
     */
    private function push(User $user, string $title, string $body, array $data): void
    {
        $tokens = $user->deviceTokens()->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        // FCM data values must be strings.
        $stringData = array_map(fn ($v) => (string) $v, $data);

        $invalid = $this->firebase->sendToTokens($tokens, $title, $body, $stringData);

        if ($invalid !== []) {
            $user->deviceTokens()->whereIn('token', $invalid)->delete();
        }
    }

    public function markRead(Notification $notification): void
    {
        if (! $notification->isRead()) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllRead(User $user): void
    {
        $user->appNotifications()->unread()->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return $user->appNotifications()->unread()->count();
    }
}
