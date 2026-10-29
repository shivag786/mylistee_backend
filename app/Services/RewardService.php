<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\FeatureFlag;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\RewardRule;
use App\Models\ShopRewardSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A shop's "Rewards & coupons": the automatic bill discount, the come-back
 * and loyalty coupons it hands out after paid orders, and the coupon codes
 * customers apply at checkout. Everything here is priced on the server; the
 * cart only shows what quote() says.
 */
class RewardService
{
    /**
     * The admin's "Rewards & coupons" switch (`owner_rewards`). Off: no shop's
     * bill discount, come-back or loyalty coupons, or coupon codes apply or show
     * anywhere — coins are a separate switch (LoyaltyService::isEnabled).
     */
    public function enabled(): bool
    {
        return FeatureFlag::isEnabled('owner_rewards', true);
    }

    public function settingsFor(Business $business): ShopRewardSetting
    {
        return ShopRewardSetting::firstOrNew(['business_id' => $business->id]);
    }

    /**
     * Save the three rules. A rule sent as null (or with `enabled` false) is
     * switched off; one left out of `$data` keeps its stored value.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Business $business, array $data): ShopRewardSetting
    {
        $setting = $this->settingsFor($business);

        foreach (['bill_discount', 'comeback', 'loyalty'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $rule = $data[$key];
            if ($key === 'loyalty') {
                $setting->loyalty = is_array($rule) && ($rule['enabled'] ?? true) ? $this->cleanLoyalty($rule) : null;

                continue;
            }
            $setting->{$key} = is_array($rule) && ($rule['enabled'] ?? true) && (float) ($rule['value'] ?? 0) > 0
                ? $this->cleanRule($key, $rule)
                : null;
        }

        $setting->save();

        return $setting;
    }

    /** @param array<string, mixed> $rule */
    private function cleanRule(string $key, array $rule): array
    {
        $clean = [
            'type' => ($rule['type'] ?? 'flat') === 'percent' ? 'percent' : 'flat',
            'value' => round((float) $rule['value'], 2),
            'max_off' => isset($rule['max_off']) && (float) $rule['max_off'] > 0 ? round((float) $rule['max_off'], 2) : null,
        ];

        return match ($key) {
            'bill_discount' => $clean + ['min_bill' => round((float) ($rule['min_bill'] ?? 0), 2)],
            'comeback' => $clean + [
                'min_bill' => isset($rule['min_bill']) && (float) $rule['min_bill'] > 0 ? round((float) $rule['min_bill'], 2) : null,
                'days' => max(1, min(90, (int) ($rule['days'] ?? 15))),
            ],
            // The earned coupon works on a bill of at least this much (optional).
            'loyalty_step' => $clean + [
                'min_bill' => isset($rule['min_bill']) && (float) $rule['min_bill'] > 0 ? round((float) $rule['min_bill'], 2) : null,
            ],
            default => $clean,
        };
    }

    /** Most steps a shop can set up, and the furthest order one can sit on. */
    public const MAX_STEPS = 10;

    public const MAX_STEP_AT = 100;

    /**
     * The loyalty rule as saved: `{steps: [{at, type, value, max_off}], repeat}`,
     * steps sorted by order number, one per number, each with a real discount.
     * Null when no step survives (the reward is then off).
     *
     * @param  array<string, mixed>  $rule
     * @return array{steps: list<array<string, mixed>>, repeat: bool}|null
     */
    private function cleanLoyalty(array $rule): ?array
    {
        $steps = [];
        foreach ($this->loyaltySteps($rule)['steps'] ?? [] as $step) {
            $at = (int) ($step['at'] ?? 0);
            if ($at < 1 || $at > self::MAX_STEP_AT || (float) ($step['value'] ?? 0) <= 0) {
                continue;
            }
            $steps[$at] = ['at' => $at] + $this->cleanRule('loyalty_step', $step);
        }
        if ($steps === []) {
            return null;
        }
        ksort($steps);

        return [
            'steps' => array_slice(array_values($steps), 0, self::MAX_STEPS),
            'repeat' => (bool) ($rule['repeat'] ?? true),
        ];
    }

    /**
     * Read a stored loyalty rule in either shape. Rules saved before steps
     * existed were `{every, type, value, max_off}` — one reward on every Nth
     * order — which is exactly one step at N that repeats.
     *
     * @param  array<string, mixed>|null  $rule
     * @return array{steps: list<array<string, mixed>>, repeat: bool}|null
     */
    public function loyaltySteps(?array $rule): ?array
    {
        if (! $rule) {
            return null;
        }
        if (isset($rule['steps']) && is_array($rule['steps'])) {
            $steps = array_values(array_filter($rule['steps'], 'is_array'));
            usort($steps, fn ($a, $b) => (int) ($a['at'] ?? 0) <=> (int) ($b['at'] ?? 0));

            return $steps === [] ? null : ['steps' => $steps, 'repeat' => (bool) ($rule['repeat'] ?? true)];
        }
        if (isset($rule['every'])) {
            return [
                'steps' => [[
                    'at' => (int) $rule['every'],
                    'type' => $rule['type'] ?? 'flat',
                    'value' => $rule['value'] ?? 0,
                    'max_off' => $rule['max_off'] ?? null,
                ]],
                'repeat' => true,
            ];
        }

        return null;
    }

    /**
     * Where a customer with `$paidOrders` paid orders stands on the card. A
     * repeating card starts over after its last step, so on a 3 · 5 · 6 card
     * the 9th order is the 3rd of the second round.
     *
     * @param  array{steps: list<array<string, mixed>>, repeat: bool}  $loyalty
     * @return array{cycle: int, position: int, round: int, finished: bool}
     */
    public function loyaltyPosition(array $loyalty, int $paidOrders): array
    {
        $cycle = $this->loyaltyCycle($loyalty);

        if (! $loyalty['repeat']) {
            return [
                'cycle' => $cycle,
                'position' => min($paidOrders, $cycle),
                'round' => 1,
                'finished' => $paidOrders >= $cycle,
            ];
        }

        return [
            'cycle' => $cycle,
            'position' => $paidOrders % $cycle,
            'round' => intdiv($paidOrders, $cycle) + 1,
            'finished' => false,
        ];
    }

    /** @param array{steps: list<array<string, mixed>>, repeat: bool} $loyalty */
    private function loyaltyCycle(array $loyalty): int
    {
        return max(1, (int) $loyalty['steps'][count($loyalty['steps']) - 1]['at']);
    }

    /**
     * The step a customer's `$paidOrders`-th paid order lands on, if any.
     *
     * @param  array{steps: list<array<string, mixed>>, repeat: bool}  $loyalty
     * @return array<string, mixed>|null
     */
    private function stepForOrder(array $loyalty, int $paidOrders): ?array
    {
        if ($paidOrders < 1) {
            return null;
        }
        $cycle = $this->loyaltyCycle($loyalty);
        if (! $loyalty['repeat'] && $paidOrders > $cycle) {
            return null;
        }
        $at = $loyalty['repeat'] ? (($paidOrders - 1) % $cycle) + 1 : $paidOrders;

        foreach ($loyalty['steps'] as $step) {
            if ((int) $step['at'] === $at) {
                return $step;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------- checkout -- */

    /** The automatic discount on a bill of `$subtotal` (0 below the minimum). */
    public function billDiscount(Business $business, float $subtotal): float
    {
        if (! $this->enabled()) {
            return 0.0;
        }
        $rule = $this->settingsFor($business)->bill_discount;
        if (! $rule || $subtotal < (float) $rule['min_bill']) {
            return 0.0;
        }

        return RewardRule::discount($rule, $subtotal);
    }

    /**
     * "Add ₹120 more to get ₹50 off" — while the bill is under the minimum.
     *
     * @return array{needed: float, minBill: float, label: string}|null
     */
    public function billNudge(Business $business, float $subtotal): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $rule = $this->settingsFor($business)->bill_discount;
        if (! $rule || $subtotal >= (float) $rule['min_bill']) {
            return null;
        }

        return [
            'needed' => round((float) $rule['min_bill'] - $subtotal, 2),
            'minBill' => (float) $rule['min_bill'],
            'label' => RewardRule::label($rule['type'], (float) $rule['value'], $rule['max_off'] ?? null),
        ];
    }

    /**
     * Coupons a customer could apply here: the shop's live codes plus their own
     * earned ones, minus any they have used up. A guest sees the shop's codes.
     *
     * @return Collection<int, Coupon>
     */
    public function couponsFor(Business $business, ?User $customer): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }
        return Coupon::where('business_id', $business->id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('customer_id')->when($customer, fn ($q) => $q->orWhere('customer_id', $customer->id)))
            ->orderByRaw('customer_id is null')
            ->latest('id')
            ->get()
            ->filter(fn (Coupon $c) => $c->isLive() && ($customer === null || $this->usesLeft($c, $customer) > 0))
            ->values();
    }

    public function usesLeft(Coupon $coupon, User $customer): int
    {
        $used = $coupon->redemptions()->where('customer_id', $customer->id)->count();

        return max(0, $coupon->per_customer_limit - $used);
    }

    /**
     * The coupon behind a code, checked for this customer and bill.
     *
     * @throws ValidationException with a message the cart can show as-is
     */
    public function couponForCheckout(Business $business, ?User $customer, string $code, float $subtotal): Coupon
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['couponCode' => [$message]]);
        if (! $this->enabled()) {
            $fail('Coupons aren’t available right now.');
        }

        $coupon = Coupon::where('business_id', $business->id)->where('code', Str::upper(trim($code)))->first();
        if ($coupon === null || ($coupon->customer_id !== null && $coupon->customer_id !== $customer?->id)) {
            $fail('That code isn’t valid at this shop.');
        }
        if (! $coupon->isLive()) {
            $fail($coupon->expires_at?->isPast() ? 'This coupon has expired.' : 'This coupon isn’t active right now.');
        }
        if ($customer !== null && $this->usesLeft($coupon, $customer) <= 0) {
            $fail('You’ve already used this coupon.');
        }
        if ($coupon->min_bill !== null && $subtotal < (float) $coupon->min_bill) {
            $needed = RewardRule::money((float) $coupon->min_bill - $subtotal);
            $fail("Add ₹{$needed} more to use {$coupon->code}.");
        }

        return $coupon;
    }

    public function redeem(Coupon $coupon, Order $order, User $customer, float $amount): void
    {
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'amount' => $amount,
        ]);
        $coupon->increment('used_count');
    }

    /** A cancelled order gives its coupon back. Safe to call more than once. */
    public function release(Order $order): void
    {
        $redemption = CouponRedemption::where('order_id', $order->id)->first();
        if ($redemption === null) {
            return;
        }
        Coupon::whereKey($redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        $redemption->delete();
    }

    /* ------------------------------------------------------ after a sale -- */

    /** Paid orders this customer has had here — what loyalty counts. */
    public function paidOrderCount(Business $business, User $customer): int
    {
        return Order::where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('paid_at')
            ->count();
    }

    /**
     * Hand out what a paid order earned: a come-back coupon for the next
     * visit, and a loyalty coupon on every Nth paid order.
     *
     * @return list<Coupon>
     */
    public function issueAfterPaid(Order $order): array
    {
        $customer = $order->customer;
        if ($customer === null || ! $this->enabled()) {
            return [];
        }
        $business = $order->business;
        $setting = $this->settingsFor($business);
        $issued = [];

        $comeback = $setting->comeback;
        if ($comeback && ($comeback['min_bill'] === null || (float) $order->subtotal >= (float) $comeback['min_bill'])) {
            $issued[] = $this->personalCoupon($order, Coupon::KIND_COMEBACK, 'Come-back reward', $comeback, (int) $comeback['days']);
        }

        $loyalty = $this->loyaltySteps($setting->loyalty);
        $step = $loyalty ? $this->stepForOrder($loyalty, $this->paidOrderCount($business, $customer)) : null;
        if ($step !== null) {
            $issued[] = $this->personalCoupon(
                $order,
                Coupon::KIND_LOYALTY,
                'Loyalty reward · '.RewardRule::ordinal((int) $step['at']).' order',
                $step,
                30,
                (int) $step['at'],
            );
        }

        return $issued;
    }

    /** @param array<string, mixed> $rule */
    private function personalCoupon(Order $order, string $kind, string $title, array $rule, int $days, ?int $tierAt = null): Coupon
    {
        $prefix = $kind === Coupon::KIND_LOYALTY ? 'LOYAL' : 'BACK';
        do {
            $code = $prefix.Str::upper(Str::random(5));
        } while (Coupon::where('business_id', $order->business_id)->where('code', $code)->exists());

        return Coupon::create([
            'business_id' => $order->business_id,
            'customer_id' => $order->customer_id,
            'code' => $code,
            'title' => $title,
            'kind' => $kind,
            'tier_at' => $tierAt,
            'discount_type' => $rule['type'],
            'value' => $rule['value'],
            'max_discount' => $rule['max_off'] ?? null,
            'min_bill' => $rule['min_bill'] ?? null,
            'per_customer_limit' => 1,
            'usage_limit' => 1,
            'expires_at' => Carbon::now()->addDays($days)->endOfDay(),
        ]);
    }

    /* ---------------------------------------------------------- showcase -- */

    /**
     * Everything a customer gains by ordering here, for the shop page and the
     * cart: the rules in words, their loyalty progress, and their coupons.
     *
     * @return array<string, mixed>
     */
    public function perks(Business $business, ?User $customer): array
    {
        if (! $this->enabled()) {
            return ['billDiscount' => null, 'comeback' => null, 'loyalty' => null, 'coupons' => []];
        }
        $setting = $this->settingsFor($business);
        $bill = $setting->bill_discount;
        $comeback = $setting->comeback;
        $loyalty = $this->loyaltySteps($setting->loyalty);

        return [
            'billDiscount' => $bill ? [
                'minBill' => (float) $bill['min_bill'],
                'label' => RewardRule::label($bill['type'], (float) $bill['value'], $bill['max_off'] ?? null),
            ] : null,
            'comeback' => $comeback ? [
                'days' => (int) $comeback['days'],
                'minBill' => $comeback['min_bill'] !== null ? (float) $comeback['min_bill'] : null,
                'label' => RewardRule::label($comeback['type'], (float) $comeback['value'], $comeback['max_off'] ?? null),
            ] : null,
            'loyalty' => $loyalty ? $this->loyaltyCard($business, $customer, $loyalty) : null,
            'coupons' => $this->couponsFor($business, $customer)->map(fn (Coupon $c) => $this->couponPayload($c))->all(),
        ];
    }

    /**
     * The loyalty card as a customer sees it: every step with its reward, how
     * far along they are, and the next step to reach.
     *
     * @param  array{steps: list<array<string, mixed>>, repeat: bool}  $loyalty
     * @return array<string, mixed>
     */
    private function loyaltyCard(Business $business, ?User $customer, array $loyalty): array
    {
        $paid = $customer ? $this->paidOrderCount($business, $customer) : null;
        $where = $paid !== null ? $this->loyaltyPosition($loyalty, $paid) : null;
        $position = $where['position'] ?? 0;
        $finished = $where['finished'] ?? false;

        $steps = array_map(fn (array $step) => [
            'at' => (int) $step['at'],
            'label' => RewardRule::label($step['type'], (float) $step['value'], $step['max_off'] ?? null)
                .(! empty($step['min_bill']) ? ' on ₹'.RewardRule::money((float) $step['min_bill']).'+' : ''),
            'minBill' => ! empty($step['min_bill']) ? (float) $step['min_bill'] : null,
            'reached' => $finished || ($where !== null && $position >= (int) $step['at']),
        ], $loyalty['steps']);

        $next = null;
        if (! $finished) {
            foreach ($steps as $step) {
                if ($step['at'] > $position) {
                    $next = $step;
                    break;
                }
            }
        }

        return [
            'steps' => $steps,
            'repeat' => $loyalty['repeat'],
            // Orders in one round of the card (the last step's number).
            'every' => $this->loyaltyCycle($loyalty),
            // Paid orders into the current round; null for a guest.
            'done' => $where !== null ? $position : null,
            'totalOrders' => $paid,
            'round' => $where['round'] ?? null,
            'finished' => $finished,
            // The next step to reach, and how many orders until it.
            'next' => $next ? $next + ['ordersLeft' => $next['at'] - $position] : null,
            'label' => ($next ?? $steps[0])['label'],
        ];
    }

    /** @return array<string, mixed> */
    public function couponPayload(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'title' => $coupon->title,
            'kind' => $coupon->kind,
            'label' => $coupon->label(),
            'minBill' => $coupon->min_bill !== null ? (float) $coupon->min_bill : null,
            'expiresAt' => $coupon->expires_at?->toIso8601String(),
            // Earned by this customer, rather than a code anyone can use.
            'personal' => $coupon->customer_id !== null,
        ];
    }

    /** One line for shop cards in lists: the strongest pull, or null. */
    public function headline(?ShopRewardSetting $setting): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($setting === null) {
            return null;
        }
        if ($bill = $setting->bill_discount) {
            $label = RewardRule::label($bill['type'], (float) $bill['value'], $bill['max_off'] ?? null);

            return (float) $bill['min_bill'] > 0 ? "{$label} above ₹".RewardRule::money((float) $bill['min_bill']) : $label;
        }
        if ($loyalty = $this->loyaltySteps($setting->loyalty)) {
            $steps = $loyalty['steps'];
            if (count($steps) === 1) {
                $step = $steps[0];

                return ($loyalty['repeat'] ? 'Every ' : 'On your ').RewardRule::ordinal((int) $step['at']).' order '
                    .RewardRule::label($step['type'], (float) $step['value'], $step['max_off'] ?? null);
            }
            $ats = array_map(fn ($s) => RewardRule::ordinal((int) $s['at']), $steps);
            $last = array_pop($ats);

            return 'Rewards on your '.implode(', ', $ats).' & '.$last.' order';
        }
        if ($comeback = $setting->comeback) {
            return RewardRule::label($comeback['type'], (float) $comeback['value'], $comeback['max_off'] ?? null).' on your next visit';
        }

        return null;
    }

    /* ------------------------------------------------------- scratch cards -- */

    /**
     * The loyalty rewards a customer has earned at every shop, newest first —
     * shown as scratch cards, each opened once.
     *
     * @return list<array<string, mixed>>
     */
    public function scratchCards(User $customer): array
    {
        return Coupon::where('customer_id', $customer->id)
            ->where('kind', Coupon::KIND_LOYALTY)
            ->with('business:id,name,slug,logo_path')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Coupon $c) => $this->cardPayload($c))
            ->all();
    }

    /**
     * Scratch a card open. Opening twice is harmless and keeps the first time.
     *
     * @return array<string, mixed>|null null when the card is not this customer's
     */
    public function reveal(User $customer, string $uuid): ?array
    {
        $coupon = Coupon::where('customer_id', $customer->id)
            ->where('kind', Coupon::KIND_LOYALTY)
            ->where('uuid', $uuid)
            ->with('business:id,name,slug,logo_path')
            ->first();
        if ($coupon === null) {
            return null;
        }
        if ($coupon->revealed_at === null) {
            $coupon->forceFill(['revealed_at' => Carbon::now()])->save();
        }

        return $this->cardPayload($coupon);
    }

    /** @return array<string, mixed> */
    private function cardPayload(Coupon $coupon): array
    {
        $used = $coupon->used_count > 0;
        $expired = ! $used && $coupon->expires_at !== null && $coupon->expires_at->isPast();
        $revealed = $coupon->revealed_at !== null;

        return [
            'id' => $coupon->uuid,
            'tierAt' => $coupon->tier_at,
            'title' => $coupon->title,
            // The reward stays hidden until the card is scratched.
            'label' => $revealed ? $coupon->label() : null,
            'code' => $revealed ? $coupon->code : null,
            'minBill' => $coupon->min_bill !== null ? (float) $coupon->min_bill : null,
            'revealed' => $revealed,
            'status' => $used ? 'used' : ($expired ? 'expired' : 'active'),
            'expiresAt' => $coupon->expires_at?->toIso8601String(),
            'earnedAt' => $coupon->created_at?->toIso8601String(),
            'business' => $coupon->business ? [
                'name' => $coupon->business->name,
                'slug' => $coupon->business->slug,
                'logo' => $coupon->business->logo_path ? Storage::disk('public')->url($coupon->business->logo_path) : null,
            ] : null,
        ];
    }

    /* ---------------------------------------------------- repeat customers -- */

    /**
     * Who keeps coming back: every customer with a paid order here, how many,
     * when they last ordered, the loyalty rewards they earned and used, and
     * where they stand on the card. Most orders first.
     *
     * @return array{customers: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function repeatCustomers(Business $business, int $limit = 200): array
    {
        $rows = Order::query()
            ->where('business_id', $business->id)
            ->whereNotNull('paid_at')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) as orders, max(paid_at) as last_paid_at, sum(total) as spent')
            ->groupBy('customer_id')
            ->orderByDesc('orders')
            ->orderByDesc('last_paid_at')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('customer_id');
        $users = User::whereIn('id', $ids)->get(['id', 'name', 'avatar_url'])->keyBy('id');
        $earned = Coupon::where('business_id', $business->id)
            ->where('kind', Coupon::KIND_LOYALTY)
            ->whereIn('customer_id', $ids)
            ->selectRaw('customer_id, count(*) as earned, sum(case when used_count > 0 then 1 else 0 end) as used')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $loyalty = $this->loyaltySteps($this->settingsFor($business)->loyalty);

        $customers = $rows->map(function ($row) use ($users, $earned, $loyalty): array {
            $orders = (int) $row->orders;
            $where = $loyalty ? $this->loyaltyPosition($loyalty, $orders) : null;
            $next = null;
            if ($loyalty && ! $where['finished']) {
                foreach ($loyalty['steps'] as $step) {
                    if ((int) $step['at'] > $where['position']) {
                        $next = ['at' => (int) $step['at'], 'ordersLeft' => (int) $step['at'] - $where['position']];
                        break;
                    }
                }
            }
            $user = $users->get($row->customer_id);
            $rewards = $earned->get($row->customer_id);

            return [
                'name' => $user?->name ?: 'Customer',
                'avatar' => $user?->avatar_url,
                'orders' => $orders,
                'spent' => round((float) $row->spent, 2),
                'lastOrderAt' => $row->last_paid_at ? Carbon::parse($row->last_paid_at)->toIso8601String() : null,
                'rewardsEarned' => (int) ($rewards->earned ?? 0),
                'rewardsUsed' => (int) ($rewards->used ?? 0),
                'position' => $where['position'] ?? null,
                'round' => $where['round'] ?? null,
                'finished' => $where['finished'] ?? false,
                'next' => $next,
            ];
        })->all();

        return [
            'customers' => $customers,
            'summary' => [
                'customers' => count($customers),
                'repeat' => count(array_filter($customers, fn ($c) => $c['orders'] > 1)),
                'rewardsEarned' => array_sum(array_column($customers, 'rewardsEarned')),
                'rewardsUsed' => array_sum(array_column($customers, 'rewardsUsed')),
            ],
        ];
    }
}
