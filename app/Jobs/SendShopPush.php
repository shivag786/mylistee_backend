<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\ShopPushCampaign;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one shop push campaign: an in-app notification for each customer,
 * plus an FCM push to their devices. Dispatched after the response, so it runs
 * without a queue worker.
 */
class SendShopPush implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $userIds */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $userIds,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $campaign = ShopPushCampaign::with('business')->find($this->campaignId);
        if ($campaign === null) {
            return;
        }

        $shop = $campaign->business->name;
        $data = ['link' => $campaign->link, 'business' => $campaign->business->slug];

        User::whereIn('id', $this->userIds)->chunkById(200, function ($users) use ($notifications, $campaign, $shop, $data): void {
            foreach ($users as $user) {
                $notifications->notify($user, NotificationType::ShopUpdate, "{$shop}: {$campaign->title}", $campaign->body, $data);
            }
        });

        $campaign->update(['sent_at' => now()]);
    }
}
