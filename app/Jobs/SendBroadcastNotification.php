<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers an admin broadcast (document/phase/14 §Notification Center) to a
 * target audience off the request thread. A large broadcast can touch thousands
 * of users, so the fan-out runs on the queue — chunked so it never exhausts
 * memory — instead of blocking the admin's HTTP request.
 *
 * @see \App\Http\Controllers\Api\V1\Admin\BroadcastController
 */
class SendBroadcastNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  deep-link payload, e.g. ['link' => '/wallet']
     */
    public function __construct(
        public readonly string $target,
        public readonly string $title,
        public readonly ?string $body,
        public readonly array $data = [],
    ) {}

    public function handle(NotificationService $notifications): void
    {
        self::audienceQuery($this->target)->chunkById(200, function ($users) use ($notifications): void {
            foreach ($users as $user) {
                $notifications->notify(
                    $user,
                    NotificationType::System,
                    $this->title,
                    $this->body,
                    $this->data,
                );
            }
        });
    }

    /**
     * Active users in the broadcast's target audience. Shared with the controller
     * so it can count recipients up front without duplicating the filter.
     *
     * @return Builder<User>
     */
    public static function audienceQuery(string $target): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->when($target === 'customers', fn ($q) => $q->where('role', UserRole::Customer->value))
            ->when($target === 'owners', fn ($q) => $q->where('role', UserRole::BusinessOwner->value));
    }
}
