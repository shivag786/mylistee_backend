<?php

namespace App\Services;

use App\Enums\PromotionStatus;
use App\Models\Business;
use Illuminate\Validation\ValidationException;

/**
 * Central plan-quota gate. Limits live on the plan as data (null = unlimited),
 * so the Super Admin tunes them without a deploy. Over-limit is enforced by
 * blocking *activation* — combos/promotions are never deleted, only kept
 * inactive — so downgrades and caps are always reversible (owner request).
 */
class PlanLimitService
{
    /** Combos currently active (visible) for the business. */
    public function activeCombos(Business $business): int
    {
        return $business->combos()->where('is_visible', true)->count();
    }

    /** Promotions currently occupying an active slot (running or scheduled). */
    public function activePromotions(Business $business): int
    {
        return $business->promotions()
            ->whereIn('status', [PromotionStatus::Running->value, PromotionStatus::Scheduled->value])
            ->count();
    }

    /**
     * Guard combo activation. Called before a combo becomes visible; throws when
     * the plan's active-combo quota is already full.
     *
     * @param  int  $alreadyCounted  active combos this one is already included in (0 on create)
     *
     * @throws ValidationException
     */
    public function assertCanActivateCombo(Business $business, int $alreadyCounted = 0): void
    {
        $limit = $business->currentPlan()?->max_active_combos;
        if ($limit === null) {
            return; // unlimited
        }

        if (($this->activeCombos($business) - $alreadyCounted) >= $limit) {
            throw ValidationException::withMessages([
                'combo' => [$this->message('combo offers', $limit)],
            ]);
        }
    }

    /**
     * Guard promotion activation (running/scheduled).
     *
     * @throws ValidationException
     */
    public function assertCanActivatePromotion(Business $business, int $alreadyCounted = 0): void
    {
        $limit = $business->currentPlan()?->max_active_promotions;
        if ($limit === null) {
            return;
        }

        if (($this->activePromotions($business) - $alreadyCounted) >= $limit) {
            throw ValidationException::withMessages([
                'promotion' => [$this->message('promotions', $limit)],
            ]);
        }
    }

    /**
     * Whether the business's plan includes customer push at all (capability gate).
     * The monthly quota (`max_push_per_month`) is enforced at send time once the
     * owner→customer push feature is built; the plan column is ready for it.
     */
    public function canSendPush(Business $business): bool
    {
        $plan = $business->currentPlan();

        return $plan === null || $plan->hasFeature('push_notifications');
    }

    private function message(string $noun, int $limit): string
    {
        return "Your plan allows {$limit} active {$noun}. Turn one off or upgrade to activate another.";
    }
}
