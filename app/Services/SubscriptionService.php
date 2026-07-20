<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Subscription lifecycle (document/phase/14 §Subscription Management). Payment is
 * a placeholder (phase/14 §Payment Management "Future") — upgrading records a
 * `paid` invoice immediately, no gateway. Plan resolution is lazy: a business
 * with no active subscription falls back to the default (free) plan, so no
 * back-fill is needed for businesses created before this milestone.
 */
class SubscriptionService
{
    /**
     * The canonical current-subscription payload for a business — used by both
     * the GET and the change endpoints so the client always sees fresh state.
     *
     * @return array{plan: Plan|null, subscription: Subscription|null, usage: array<string, mixed>}
     */
    public function state(Business $business): array
    {
        $business->forgetPlanCache();
        $plan = $business->currentPlan();

        return [
            'plan' => $plan,
            'subscription' => $business->activeSubscription(),
            'usage' => $this->usage($business, $plan),
        ];
    }

    /**
     * Move a business onto a plan. Paid plans create an active subscription and a
     * paid invoice; the free/default plan simply cancels any paid subscription
     * (the fallback handles the rest).
     */
    public function subscribe(Business $business, Plan $plan, ?User $actor = null): ?Subscription
    {
        return DB::transaction(function () use ($business, $plan, $actor): ?Subscription {
            // End any current active subscription.
            $business->subscriptions()->active()->update([
                'status' => SubscriptionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);

            $business->forgetPlanCache();

            if ($plan->is_default || $plan->isFree()) {
                return null; // now on the free fallback
            }

            $startsAt = Carbon::now();
            $endsAt = match ($plan->interval) {
                'year' => $startsAt->copy()->addYear(),
                'lifetime' => null,
                default => $startsAt->copy()->addMonth(),
            };

            $subscription = $business->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'auto_renew' => true,
                'created_by' => $actor?->id,
            ]);

            $this->recordInvoice($business, $subscription, $plan, $startsAt, $endsAt);
            $business->forgetPlanCache();

            return $subscription->load('plan');
        });
    }

    /**
     * Cancel the active subscription. Access continues until the period end
     * (`ends_at`), after which plan resolution lazily falls back to free.
     */
    public function cancel(Business $business): ?Subscription
    {
        $subscription = $business->activeSubscription();
        if ($subscription === null) {
            return null; // already on free
        }

        $subscription->update([
            'auto_renew' => false,
            'cancelled_at' => now(),
        ]);
        $business->forgetPlanCache();

        return $subscription->fresh(['plan']);
    }

    /**
     * Current usage against the plan's configurable limits (null limit = unlimited).
     *
     * @return array<string, array{used: int, limit: int|null}>
     */
    public function usage(Business $business, ?Plan $plan): array
    {
        return [
            'activeOffers' => [
                'used' => $business->offers()->countsAsActive()->count(),
                'limit' => $plan?->max_active_offers,
            ],
            'galleryImages' => [
                'used' => $business->gallery()->count(),
                'limit' => $plan?->max_gallery_images,
            ],
            'qrCodes' => [
                'used' => $business->qrCode()->count(),
                'limit' => $plan?->max_qr_codes,
            ],
        ];
    }

    /** @return \Illuminate\Support\Collection<int, Invoice> */
    public function invoicesFor(Business $business)
    {
        return $business->invoices()->limit(50)->get();
    }

    private function recordInvoice(
        Business $business,
        Subscription $subscription,
        Plan $plan,
        Carbon $periodStart,
        ?Carbon $periodEnd,
    ): Invoice {
        return $business->invoices()->create([
            'number' => $this->nextInvoiceNumber(),
            'subscription_id' => $subscription->id,
            'plan_name' => $plan->name,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => InvoiceStatus::Paid,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'issued_at' => now(),
            'paid_at' => now(),
            'meta' => ['simulated' => true, 'interval' => $plan->interval],
        ]);
    }

    /** Sequential invoice number, e.g. INV-2026-000042. */
    private function nextInvoiceNumber(): string
    {
        $year = now()->year;
        $seq = Invoice::count() + 1;

        return sprintf('INV-%d-%06d', $year, $seq);
    }
}
