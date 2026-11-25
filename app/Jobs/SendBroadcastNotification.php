<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\AdminBroadcast;
use App\Models\Business;
use App\Models\User;
use App\Services\FirebaseService;
use App\Services\ShopPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Delivers an admin broadcast (document/phase/14 §Notification Center). Every
 * recipient gets an in-app notification (written in bulk) and every device
 * they signed in on gets a push — sent to Firebase 500 devices at a time
 * instead of one call per person, so thousands go out in seconds. Dead
 * device tokens are pruned and the result is stored on the broadcast.
 *
 * @see \App\Http\Controllers\Api\V1\Admin\BroadcastController
 */
class SendBroadcastNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(FirebaseService $firebase): void
    {
        $broadcast = AdminBroadcast::find($this->broadcastId);
        if ($broadcast === null) {
            return;
        }
        $broadcast->update(['status' => 'sending']);

        $data = array_filter([
            'link' => (string) ($broadcast->link ?? ''),
            // The vendor app keys a shown notification by its tag, so a fixed
            // tag would make each broadcast replace the one before it.
            'tag' => 'broadcast-'.$broadcast->uuid,
        ], fn ($v) => $v !== '');

        $pushed = 0;
        $failed = 0;

        try {
            self::audienceFor($broadcast)->select('users.id')->chunkById(500, function ($users) use ($broadcast, $data, $firebase, &$pushed, &$failed): void {
                $ids = $users->pluck('id')->all();
                $now = now();

                DB::table('notifications')->insert(array_map(fn (int $id) => [
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $id,
                    'type' => NotificationType::System->value,
                    'title' => $broadcast->title,
                    'body' => $broadcast->body,
                    'data' => $broadcast->link ? json_encode(['link' => $broadcast->link]) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $ids));

                $tokens = DB::table('device_tokens')->whereIn('user_id', $ids)->pluck('token')->all();
                $report = $firebase->multicast($tokens, $broadcast->title, (string) ($broadcast->body ?? ''), $data);
                $pushed += $report['sent'];
                $failed += $report['failed'];

                if ($report['invalid'] !== []) {
                    DB::table('device_tokens')->whereIn('token', $report['invalid'])->delete();
                }
            }, 'users.id', 'id');

            $broadcast->update(['status' => 'sent', 'pushed' => $pushed, 'failed' => $failed, 'sent_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Broadcast failed', ['broadcast' => $broadcast->id, 'error' => $e->getMessage()]);
            $broadcast->update(['status' => 'failed', 'pushed' => $pushed, 'failed' => $failed]);
        }
    }

    /** @return Builder<User> */
    public static function audienceFor(AdminBroadcast $b): Builder
    {
        return self::audienceQuery($b->target, [
            'businessId' => $b->business_id,
            'audience' => $b->audience,
            'categoryId' => $b->category_id,
            'userId' => $b->created_by,
        ]);
    }

    /**
     * Active users in a broadcast's audience. Shared with the controller so it
     * can count recipients up front without duplicating the filter.
     *
     * - all / customers / owners: everyone, customers, or shop owners (owners
     *   can be narrowed to one shop category).
     * - shop_customers: one shop's followers / customers (ShopPushService audiences).
     * - test: only the admin sending it.
     *
     * @param  array{businessId?: ?int, audience?: ?string, categoryId?: ?int, userId?: ?int}  $opts
     * @return Builder<User>
     */
    public static function audienceQuery(string $target, array $opts = []): Builder
    {
        if ($target === 'shop_customers') {
            $business = Business::find($opts['businessId'] ?? 0);

            return $business === null
                ? User::query()->whereRaw('1 = 0')
                : app(ShopPushService::class)->audienceQuery($business, $opts['audience'] ?: 'everyone');
        }

        if ($target === 'test') {
            return User::query()->where('id', $opts['userId'] ?? 0);
        }

        $categoryId = $opts['categoryId'] ?? null;

        return User::query()
            ->where('status', 'active')
            ->when($target === 'customers', fn ($q) => $q->where('role', UserRole::Customer->value))
            ->when($target === 'owners', fn ($q) => $q->where('role', UserRole::BusinessOwner->value)
                ->when($categoryId, fn ($q) => $q->whereIn('id', fn ($s) => $s->select('owner_id')->from('businesses')->where('category_id', $categoryId))));
    }
}
