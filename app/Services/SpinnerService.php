<?php

namespace App\Services;

use App\Enums\BusinessStatus;
use App\Enums\CoinSource;
use App\Enums\NotificationType;
use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\Offer;
use App\Models\Reward;
use App\Models\Spin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The spinner engine (document/phase/02 §Spinner Rules, phase/06 §Spin).
 *
 * Backend is the source of truth: the winning reward is decided here by a
 * weighted random draw over the business's live offers — the client only
 * animates the result. Enforces the one-spin-per-business-per-day limit.
 */
class SpinnerService
{
    /** Free-plan spin allowance (phase/02 §Spin Limit). */
    public const SPINS_PER_DAY = 1;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoyaltyService $loyalty,
    ) {}

    /** Has this customer already used today's spin at this business? */
    public function hasSpunToday(User $customer, Business $business): bool
    {
        return $business->spins()
            ->where('customer_id', $customer->id)
            ->whereDate('created_at', Carbon::today())
            ->count() >= self::SPINS_PER_DAY;
    }

    /** Whether the business can currently be spun (active + has live offers). */
    public function isSpinnable(Business $business): bool
    {
        return $business->status === BusinessStatus::Active
            && $business->offers()->live()->exists();
    }

    /**
     * Perform a spin and award a reward.
     *
     * @param  array{ip?: string|null, device?: string|null}  $meta
     * @return array{reward: Reward, offer: Offer, spin: Spin, coinsEarned: int}
     *
     * @throws ValidationException
     */
    public function spin(User $customer, Business $business, array $meta = []): array
    {
        if ($business->status !== BusinessStatus::Active) {
            throw ValidationException::withMessages([
                'business' => ['This business is not accepting spins right now.'],
            ]);
        }

        if ($this->hasSpunToday($customer, $business)) {
            throw ValidationException::withMessages([
                'spin' => ["You've already spun here today. Come back tomorrow!"],
            ]);
        }

        $result = DB::transaction(function () use ($customer, $business, $meta): array {
            /** @var \Illuminate\Support\Collection<int, Offer> $offers */
            $offers = $business->offers()->live()->lockForUpdate()->get();

            if ($offers->isEmpty()) {
                throw ValidationException::withMessages([
                    'business' => ['This business has no rewards available right now.'],
                ]);
            }

            $offer = $this->pickWeighted($offers);

            if ($offer->remaining_quantity !== null) {
                $offer->decrement('remaining_quantity');
            }

            $reward = Reward::create([
                'customer_id' => $customer->id,
                'business_id' => $business->id,
                'offer_id' => $offer->id,
                'title' => $offer->title,
                'reward_value' => $offer->reward_value,
                'type' => $offer->type->value,
                'status' => RewardStatus::Active,
                'won_at' => Carbon::now(),
                'expires_at' => $this->rewardExpiry($offer),
            ]);

            $spin = Spin::create([
                'customer_id' => $customer->id,
                'business_id' => $business->id,
                'offer_id' => $offer->id,
                'reward_id' => $reward->id,
                'ip_address' => $meta['ip'] ?? null,
                'device' => $meta['device'] ?? null,
            ]);

            $business->increment('total_spins');
            $business->increment('total_rewards');

            return ['reward' => $reward, 'offer' => $offer, 'spin' => $spin];
        });

        // Award Listee Coins for the spin (best-effort — never blocks the win).
        $coins = $this->loyalty->award($customer, CoinSource::Spin, $business, [
            'reference' => $result['reward'],
        ]);
        $result['coinsEarned'] = $coins?->amount ?? 0;

        $this->notifyAfterSpin($customer, $business, $result['reward']);

        return $result;
    }

    /** Notify the customer (won) and the business owner (engagement) after a spin. */
    private function notifyAfterSpin(User $customer, Business $business, Reward $reward): void
    {
        $prize = $reward->reward_value ?: $reward->title;

        $this->notifications->notify(
            $customer,
            NotificationType::RewardWon,
            'You won a reward! 🎉',
            "You won {$prize} at {$business->name}. Find it in your wallet.",
            ['link' => '/wallet', 'rewardId' => $reward->uuid],
        );

        if ($business->owner) {
            $this->notifications->notify(
                $business->owner,
                NotificationType::SpinActivity,
                'A customer just spun the wheel',
                "{$customer->name} won {$prize} at your shop.",
                ['link' => '/business/dashboard'],
            );
        }
    }

    /**
     * Weighted random pick over the offers by their `weight`.
     *
     * @param  \Illuminate\Support\Collection<int, Offer>  $offers
     */
    private function pickWeighted($offers): Offer
    {
        $total = (int) $offers->sum(fn (Offer $o) => max(1, $o->weight));
        $roll = random_int(1, $total);

        $cursor = 0;
        foreach ($offers as $offer) {
            $cursor += max(1, $offer->weight);
            if ($roll <= $cursor) {
                return $offer;
            }
        }

        return $offers->last();
    }

    /** Reward stays valid until the offer ends (at least 24h from now). */
    private function rewardExpiry(Offer $offer): Carbon
    {
        $expiry = $offer->ends_at->copy()->endOfDay();

        return $expiry->isPast() ? Carbon::now()->addDay() : $expiry;
    }
}
