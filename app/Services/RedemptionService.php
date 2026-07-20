<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reward redemption at the counter (document/phase/07 §Redemption, phase/02
 * §Redemption). The business scans/enters the customer's reward code; the
 * backend validates ownership + state and marks it redeemed. Duplicate
 * redemption is prevented by a row lock + status check inside a transaction.
 * The `rewards` table is the single source of truth — no separate ledger table.
 */
class RedemptionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Look up a reward by code for this business and assert it is redeemable.
     *
     * @throws ValidationException
     */
    public function verify(Business $business, string $code): Reward
    {
        $reward = $business->rewards()
            ->with('customer')
            ->where('code', strtoupper(trim($code)))
            ->first();

        if ($reward === null) {
            throw ValidationException::withMessages([
                'code' => ['No reward found with this code for your business.'],
            ]);
        }

        if ($reward->status === RewardStatus::Redeemed) {
            throw ValidationException::withMessages([
                'code' => ['This reward was already redeemed on '.$reward->redeemed_at?->format('d M Y').'.'],
            ]);
        }

        if ($reward->status === RewardStatus::Expired || $reward->isExpired()) {
            throw ValidationException::withMessages([
                'code' => ['This reward has expired and can no longer be redeemed.'],
            ]);
        }

        return $reward;
    }

    /**
     * Redeem a reward code. Returns the redeemed reward.
     *
     * @throws ValidationException
     */
    public function redeem(Business $business, string $code, User $redeemer): Reward
    {
        return DB::transaction(function () use ($business, $code, $redeemer): Reward {
            $reward = $business->rewards()
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            // Re-validate under the lock to prevent a double redemption race.
            if ($reward === null) {
                throw ValidationException::withMessages([
                    'code' => ['No reward found with this code for your business.'],
                ]);
            }
            if ($reward->status !== RewardStatus::Active || $reward->isExpired()) {
                throw ValidationException::withMessages([
                    'code' => ['This reward is no longer redeemable.'],
                ]);
            }

            $reward->update([
                'status' => RewardStatus::Redeemed,
                'redeemed_at' => Carbon::now(),
                'redeemed_by' => $redeemer->id,
            ]);

            return $reward->fresh('customer');
        });

        if ($reward->customer) {
            $prize = $reward->reward_value ?: $reward->title;
            $this->notifications->notify(
                $reward->customer,
                NotificationType::RewardRedeemed,
                'Reward redeemed ✅',
                "Your reward \"{$prize}\" was redeemed at {$business->name}.",
                ['link' => '/wallet'],
            );
        }

        return $reward;
    }
}
