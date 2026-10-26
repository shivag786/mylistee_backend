<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * What a shop's plan lets it use right now (admin → Plans).
 *
 * A plan switches modules on, each optionally for a number of days counted
 * from the start of the period. The free plan can itself last a number of
 * days; when they run out — or a paid plan ends — the shop drops to
 * "menu only": profile, menu, QR and personal link keep working, every
 * module locks until a plan is bought. Nothing is deleted.
 *
 * The admin's global module switches (Owner menu page) still apply on top.
 */
class PlanAccessService
{
    /** @var array<int, array<string, mixed>> per-request cache by business id */
    private array $cache = [];

    /** @return array<string, array<string, mixed>> */
    public static function modules(): array
    {
        return (array) config('plan_modules', []);
    }

    public function allows(Business $business, string $module): bool
    {
        return (bool) ($this->snapshot($business)['modules'][$module]['allowed'] ?? true);
    }

    /**
     * Can customers order here at all? Ordering must be in the plan and at
     * least one way to pay must be open.
     */
    public function orderingOpen(Business $business): bool
    {
        if (! $this->allows($business, 'ordering')) {
            return false;
        }

        return $this->allows($business, 'pay_counter')
            || ($this->allows($business, 'pay_online') && app(RazorpayService::class)->isConfigured());
    }

    /**
     * The full picture for the owner panel and the app: the plan, when it ends,
     * whether the shop is in menu-only mode, and each module's state.
     *
     * @return array{plan: ?Plan, expired: bool, endsAt: ?Carbon, freeEndsAt: ?Carbon, modules: array<string, array{allowed: bool, reason: ?string, until: ?Carbon}>}
     */
    public function snapshot(Business $business): array
    {
        return $this->cache[$business->id] ??= $this->build($business);
    }

    public function forget(Business $business): void
    {
        unset($this->cache[$business->id]);
        $business->forgetPlanCache();
    }

    /**
     * When the free period started for this shop: its sign-up, or the day the
     * admin set the free days, whichever is later — so a new limit never ends
     * an older shop's free time on the spot.
     */
    public function freeStart(Business $business, Plan $plan): Carbon
    {
        $created = Carbon::parse($business->created_at ?? now());

        return $plan->days_from !== null && $plan->days_from->greaterThan($created)
            ? $plan->days_from->copy()
            : $created;
    }

    /**
     * May this shop take the first-time offer on a plan? Only if the offer is
     * open and neither the shop nor any other shop of the same owner has ever
     * paid for a plan.
     */
    public function introEligible(Business $business, Plan $plan): bool
    {
        if (! $plan->introActive()) {
            return false;
        }

        $shopIds = Business::query()->where('owner_id', $business->owner_id)->pluck('id');

        return ! Subscription::query()->whereIn('business_id', $shopIds)->where('price', '>', 0)->exists();
    }

    /** @return array<string, mixed> */
    private function build(Business $business): array
    {
        $subscription = $business->activeSubscription();
        $plan = $subscription?->plan ?? Plan::default();

        $start = null;
        $endsAt = null;
        $freeEndsAt = null;
        $expired = false;

        if ($subscription !== null) {
            $start = Carbon::parse($subscription->starts_at ?? $subscription->created_at);
            $endsAt = $subscription->ends_at?->copy();
        } elseif ($plan !== null) {
            $start = $this->freeStart($business, $plan);
            if ($plan->duration_days !== null) {
                $freeEndsAt = $start->copy()->addDays($plan->duration_days);
                $endsAt = $freeEndsAt->copy();
                $expired = $freeEndsAt->isPast();
            }
        }

        $modules = [];
        foreach (array_keys(self::modules()) as $id) {
            $global = ! array_key_exists($id, (array) config('owner_modules', []))
                || FeatureFlag::isEnabled("owner_{$id}", true);
            $config = $plan?->moduleConfig($id) ?? ['on' => true, 'days' => null];
            $until = $config['days'] !== null && $start !== null ? $start->copy()->addDays($config['days']) : $endsAt;

            $reason = match (true) {
                ! $global => 'off',
                $expired => 'expired',
                ! $config['on'] => 'plan',
                $config['days'] !== null && $until !== null && $until->isPast() => 'days',
                default => null,
            };

            $modules[$id] = ['allowed' => $reason === null, 'reason' => $reason, 'until' => $reason === null ? $until : null];
        }

        return compact('plan', 'expired', 'endsAt', 'freeEndsAt', 'modules');
    }
}
