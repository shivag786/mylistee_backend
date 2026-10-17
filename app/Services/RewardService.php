<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\RewardRule;
use App\Models\ShopRewardSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'loyalty' => $clean + ['every' => max(2, min(50, (int) ($rule['every'] ?? 5)))],
            default => $clean,
        };
    }

    /* ---------------------------------------------------------- checkout -- */

    /** The automatic discount on a bill of `$subtotal` (0 below the minimum). */
    public function billDiscount(Business $business, float $subtotal): float
    {
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
        if ($customer === null) {
            return [];
        }
        $business = $order->business;
        $setting = $this->settingsFor($business);
        $issued = [];

        $comeback = $setting->comeback;
        if ($comeback && ($comeback['min_bill'] === null || (float) $order->subtotal >= (float) $comeback['min_bill'])) {
            $issued[] = $this->personalCoupon($order, Coupon::KIND_COMEBACK, 'Come-back reward', $comeback, (int) $comeback['days']);
        }

        $loyalty = $setting->loyalty;
        if ($loyalty && $this->paidOrderCount($business, $customer) % (int) $loyalty['every'] === 0) {
            $issued[] = $this->personalCoupon($order, Coupon::KIND_LOYALTY, 'Loyalty reward · '.RewardRule::ordinal((int) $loyalty['every']).' order', $loyalty, 30);
        }

        return $issued;
    }

    /** @param array<string, mixed> $rule */
    private function personalCoupon(Order $order, string $kind, string $title, array $rule, int $days): Coupon
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
        $setting = $this->settingsFor($business);
        $bill = $setting->bill_discount;
        $comeback = $setting->comeback;
        $loyalty = $setting->loyalty;

        $done = $loyalty && $customer ? $this->paidOrderCount($business, $customer) % (int) $loyalty['every'] : 0;

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
            'loyalty' => $loyalty ? [
                'every' => (int) $loyalty['every'],
                // Paid orders towards the next reward; null for a guest.
                'done' => $customer ? $done : null,
                'label' => RewardRule::label($loyalty['type'], (float) $loyalty['value'], $loyalty['max_off'] ?? null),
            ] : null,
            'coupons' => $this->couponsFor($business, $customer)->map(fn (Coupon $c) => $this->couponPayload($c))->all(),
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
        if ($setting === null) {
            return null;
        }
        if ($bill = $setting->bill_discount) {
            $label = RewardRule::label($bill['type'], (float) $bill['value'], $bill['max_off'] ?? null);

            return (float) $bill['min_bill'] > 0 ? "{$label} above ₹".RewardRule::money((float) $bill['min_bill']) : $label;
        }
        if ($loyalty = $setting->loyalty) {
            return 'Every '.RewardRule::ordinal((int) $loyalty['every']).' order '.RewardRule::label($loyalty['type'], (float) $loyalty['value'], $loyalty['max_off'] ?? null);
        }
        if ($comeback = $setting->comeback) {
            return RewardRule::label($comeback['type'], (float) $comeback['value'], $comeback['max_off'] ?? null).' on your next visit';
        }

        return null;
    }
}
