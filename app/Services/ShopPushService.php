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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shop → customer push notifications. A shop messages one slice of the people
 * connected to it (followers, past customers, lapsed customers); never random
 * users. Guard rails keep it from turning into spam: the admin switches the
 * module on, the plan sets a monthly reach and send count and how often one
 * customer can be messaged a month, one send per shop per day, and nothing
 * goes out during quiet hours. The plan also decides whether the shop sees its
 * customers' email and phone in full or masked.
 */
class ShopPushService
{
    /** Audience key => label, in the order the owner sees them. */
    public const AUDIENCES = [
        'everyone' => 'Followers + customers',
        'followers' => 'Followers',
        'customers' => 'Ordered from you',
        'lapsed' => "Haven't ordered in 30 days",
        'occasions' => 'Birthdays & anniversaries this week',
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
                ->orWhereIn('id', $ordered)))
            // Followers and customers with their own or a saved person's
            // birthday / anniversary in the next 7 days.
            ->when($audience === 'occasions', fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('id', $followers)->orWhereIn('id', $ordered))
                ->whereIn('id', app(OccasionService::class)->customerIdsWithOccasionSoon(7)));
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
            // How many notifications one customer can get from this shop a month.
            'perCustomer' => ['limit' => $plan?->push_per_customer_per_month],
            'customerDetails' => $plan?->showsFullCustomerDetails() ? 'full' : 'masked',
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
     * Customers who already got the plan's per-customer number this month are
     * skipped. With `customerIds` only those customers (within the audience)
     * get it.
     *
     * @param  array{audience: string, title: string, body?: ?string, link?: ?string, limit?: ?int, customerIds?: ?list<string>}  $data
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
        $picked = $data['customerIds'] ?? null;
        $users = $this->audienceQuery($business, $data['audience'])
            ->tap(fn ($q) => $this->skipCapped($q, $business, $plan?->push_per_customer_per_month))
            ->when(! empty($picked), fn ($q) => $q->whereIn('uuid', $picked))
            ->latest('id')->limit($take)->get(['id']);

        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['push' => [
                $this->audienceQuery($business, $data['audience'])->exists()
                    ? 'These customers already got all the notifications your plan allows this month.'
                    : 'Nobody in this group yet.',
            ]]);
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

        // Who it went to, for the per-customer monthly cap.
        $now = now();
        foreach ($users->pluck('id')->chunk(500) as $chunk) {
            DB::table('shop_push_recipients')->insert($chunk->map(fn (int $id) => [
                'campaign_id' => $campaign->id,
                'business_id' => $business->id,
                'user_id' => $id,
                'created_at' => $now,
            ])->values()->all());
        }

        SendShopPush::dispatchAfterResponse($campaign->id, $users->pluck('id')->all());

        return $campaign;
    }

    /**
     * One page of an audience's customers for the notify screen: name, email
     * and phone (masked unless the plan shows them in full), whether they get
     * it on a phone, and how many notifications they got from the shop this
     * month. In masked mode the search only looks at names, so it can't be
     * used to guess a hidden email or number.
     *
     * @return array{customers: list<array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    public function customers(Business $business, string $audience, ?string $search = null, int $page = 1, int $perPage = 30): array
    {
        $plan = $business->currentPlan();
        $full = (bool) $plan?->showsFullCustomerDetails();
        $cap = $plan?->push_per_customer_per_month;
        $search = trim((string) $search);

        $paginator = $this->audienceQuery($business, $audience)
            ->select(['id', 'uuid', 'name', 'email', 'phone'])
            ->addSelect(['sent_this_month' => $this->receiptsThisMonth($business)
                ->selectRaw('count(*)')
                ->whereColumn('shop_push_recipients.user_id', 'users.id')])
            ->withExists('deviceTokens as on_phone')
            ->when($search !== '', function ($q) use ($search, $full) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->when($full, fn ($w) => $w->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)));
            })
            ->latest('id')
            ->paginate($perPage, page: max(1, $page));

        return [
            'customers' => collect($paginator->items())->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $full ? $u->email : self::maskEmail($u->email),
                'phone' => $full ? $u->phone : self::maskPhone($u->phone),
                'onPhone' => (bool) $u->on_phone,
                'sentThisMonth' => (int) $u->sent_this_month,
                'capped' => $cap !== null && (int) $u->sent_this_month >= $cap,
            ])->values()->all(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * One sent notification and the people it went to, a page at a time.
     * Contact details follow the plan, as in [customers].
     *
     * @return array<string, mixed>|null null when the shop has no such notification
     */
    public function recipients(Business $business, string $uuid, int $page = 1, int $perPage = 10): ?array
    {
        $campaign = ShopPushCampaign::where('business_id', $business->id)->where('uuid', $uuid)->first();
        if ($campaign === null) {
            return null;
        }
        $full = (bool) $business->currentPlan()?->showsFullCustomerDetails();

        $paginator = User::query()
            ->join('shop_push_recipients', 'shop_push_recipients.user_id', '=', 'users.id')
            ->where('shop_push_recipients.campaign_id', $campaign->id)
            ->select(['users.id', 'users.uuid', 'users.name', 'users.phone'])
            ->withExists('deviceTokens as on_phone')
            ->orderBy('shop_push_recipients.id')
            ->paginate($perPage, page: max(1, $page));

        return [
            'campaign' => [
                'id' => $campaign->uuid,
                'title' => $campaign->title,
                'body' => $campaign->body,
                'audience' => self::AUDIENCES[$campaign->audience] ?? $campaign->audience,
                'recipients' => $campaign->recipients,
                'reachable' => $campaign->reachable,
                'sentAt' => ($campaign->sent_at ?? $campaign->created_at)?->toIso8601String(),
            ],
            'people' => collect($paginator->items())->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'phone' => $full ? $u->phone : self::maskPhone($u->phone),
                'onPhone' => (bool) $u->on_phone,
            ])->values()->all(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /** "rahul.k@gmail.com" -> "ra*****@gmail.com" */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }
        if (! str_contains($email, '@')) {
            return str_repeat('*', 6);
        }
        [$local, $domain] = explode('@', $email, 2);
        $keep = mb_strlen($local) > 3 ? 2 : 1;

        return mb_substr($local, 0, $keep).str_repeat('*', max(3, mb_strlen($local) - $keep)).'@'.$domain;
    }

    /** "9876543210" -> "98******10" */
    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 2).str_repeat('*', strlen($digits) - 4).substr($digits, -2);
    }

    /** Leave out customers who already got `$cap` notifications from the shop this month. */
    private function skipCapped(Builder $query, Business $business, ?int $cap): void
    {
        if ($cap === null) {
            return;
        }

        $query->whereNotIn('id', $this->receiptsThisMonth($business)
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [$cap]));
    }

    private function receiptsThisMonth(Business $business): QueryBuilder
    {
        return DB::table('shop_push_recipients')
            ->where('shop_push_recipients.business_id', $business->id)
            ->where('shop_push_recipients.created_at', '>=', $this->localStartOf('month'));
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
