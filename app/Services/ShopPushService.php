<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendShopPush;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\ShopPushCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shop → customer push notifications. A shop messages one slice of the people
 * connected to it (followers, past customers, lapsed customers); never random
 * users. Guard rails keep it from turning into spam: the admin switches the
 * module on, the plan sets a monthly reach and send count, one send per shop
 * per day, and nothing goes out during quiet hours.
 */
class ShopPushService
{
    /** Audience key => label, in the order the owner sees them. */
    public const AUDIENCES = [
        'everyone' => 'Followers + customers',
        'followers' => 'Followers',
        'customers' => 'Ordered from you',
        'lapsed' => "Haven't ordered in 30 days",
    ];

    /** No pushes between these local hours (22:00 → 08:00). */
    private const QUIET_FROM = 22;

    private const QUIET_UNTIL = 8;

    public function __construct(private readonly PlanLimitService $limits) {}

    /** The customers in an audience — active customer accounts only. */
    public function audienceQuery(Business $business, string $audience): Builder
    {
        $followers = fn ($q) => $q->select('customer_id')->from('favorite_businesses')->where('business_id', $business->id);
        $ordered = fn ($q) => $q->select('customer_id')->from('orders')->where('business_id', $business->id);

        return User::query()
            ->where('role', UserRole::Customer->value)
            ->where('status', UserStatus::Active->value)
            ->when($audience === 'followers', fn ($q) => $q->whereIn('id', $followers))
            ->when($audience === 'customers', fn ($q) => $q->whereIn('id', $ordered))
            ->when($audience === 'lapsed', fn ($q) => $q
                ->whereIn('id', $ordered)
                ->whereNotIn('id', fn ($s) => $s->select('customer_id')->from('orders')
                    ->where('business_id', $business->id)
                    ->where('created_at', '>=', now()->subDays(30))))
            ->when($audience === 'everyone', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('id', $followers)
                ->orWhereIn('id', $ordered)));
    }

    /**
     * Everything the owner's screen needs: whether sending is possible right
     * now (and why not), the month's quota, each audience's size and recent sends.
     *
     * @return array<string, mixed>
     */
    public function summary(Business $business): array
    {
        $plan = $business->currentPlan();
        $reachLimit = $plan?->push_reach_per_month;
        $sendLimit = $plan?->max_push_per_month;
        $month = $this->monthCampaigns($business);
        $reachUsed = (int) $month->sum('recipients');
        $sendsUsed = $month->count();

        $audiences = [];
        foreach (self::AUDIENCES as $key => $label) {
            $query = $this->audienceQuery($business, $key);
            $audiences[] = [
                'key' => $key,
                'label' => $label,
                'count' => (clone $query)->count(),
                // Only people who allowed notifications on a device get the push.
                'reachable' => (clone $query)->whereHas('deviceTokens')->count(),
            ];
        }

        return [
            'blockedReason' => $this->blockedReason($business, $reachLimit, $reachUsed, $sendLimit, $sendsUsed),
            'reach' => [
                'limit' => $reachLimit,
                'used' => $reachUsed,
                'remaining' => $reachLimit === null ? null : max(0, $reachLimit - $reachUsed),
            ],
            'sends' => [
                'limit' => $sendLimit,
                'used' => $sendsUsed,
            ],
            'audiences' => $audiences,
            'recent' => $business->hasMany(ShopPushCampaign::class)->latest('id')->limit(10)->get()
                ->map(fn (ShopPushCampaign $c) => [
                    'id' => $c->uuid,
                    'title' => $c->title,
                    'body' => $c->body,
                    'audience' => self::AUDIENCES[$c->audience] ?? $c->audience,
                    'recipients' => $c->recipients,
                    'reachable' => $c->reachable,
                    'sentAt' => ($c->sent_at ?? $c->created_at)?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Send a notification to (up to `limit` of) an audience. The delivery runs
     * after the response, so it needs no queue worker.
     *
     * @param  array{audience: string, title: string, body?: ?string, link?: ?string, limit?: ?int}  $data
     */
    public function send(Business $business, User $actor, array $data): ShopPushCampaign
    {
        $plan = $business->currentPlan();
        $reachLimit = $plan?->push_reach_per_month;
        $month = $this->monthCampaigns($business);
        $reachUsed = (int) $month->sum('recipients');

        $reason = $this->blockedReason($business, $reachLimit, $reachUsed, $plan?->max_push_per_month, $month->count());
        if ($reason !== null) {
            throw ValidationException::withMessages(['push' => [$reason]]);
        }

        // Newest connections first; capped by the owner's choice and the quota left.
        $cap = $reachLimit === null ? PHP_INT_MAX : $reachLimit - $reachUsed;
        $take = min($cap, (int) ($data['limit'] ?? PHP_INT_MAX));
        $users = $this->audienceQuery($business, $data['audience'])->latest('id')->limit($take)->get(['id']);

        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['push' => ['Nobody in this group yet.']]);
        }

        $campaign = ShopPushCampaign::create([
            'business_id' => $business->id,
            'audience' => $data['audience'],
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? "/b/{$business->slug}",
            'recipients' => $users->count(),
            'reachable' => User::whereIn('id', $users->pluck('id'))->whereHas('deviceTokens')->count(),
            'created_by' => $actor->id,
        ]);

        SendShopPush::dispatchAfterResponse($campaign->id, $users->pluck('id')->all());

        return $campaign;
    }

    /** Why the shop can't send right now, or null when it can. */
    private function blockedReason(Business $business, ?int $reachLimit, int $reachUsed, ?int $sendLimit, int $sendsUsed): ?string
    {
        if (! FeatureFlag::isEnabled('owner_notifications', false)) {
            return 'Customer notifications are not switched on for shops yet.';
        }
        if (! $this->limits->canSendPush($business)) {
            return 'Your plan does not include customer notifications. Upgrade to send them.';
        }
        if ($reachLimit !== null && $reachUsed >= $reachLimit) {
            return "You've reached all {$reachLimit} customers your plan allows this month.";
        }
        if ($sendLimit !== null && $sendsUsed >= $sendLimit) {
            return "Your plan allows {$sendLimit} notifications a month — all used.";
        }

        $local = now(config('app.business_timezone'));
        if ($local->hour >= self::QUIET_FROM || $local->hour < self::QUIET_UNTIL) {
            return 'Notifications pause from 10 PM to 8 AM so customers aren’t disturbed. Send after 8 AM.';
        }
        if ($business->hasMany(ShopPushCampaign::class)->where('created_at', '>=', $this->localStartOf('day'))->exists()) {
            return 'One notification a day keeps customers interested. Send again tomorrow.';
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, ShopPushCampaign> */
    private function monthCampaigns(Business $business)
    {
        return $business->hasMany(ShopPushCampaign::class)
            ->where('created_at', '>=', $this->localStartOf('month'))
            ->get(['id', 'recipients']);
    }

    /** Start of the shop-local day/month, as a UTC instant for comparing timestamps. */
    private function localStartOf(string $unit): Carbon
    {
        return now(config('app.business_timezone'))->startOf($unit)->utc();
    }
}
