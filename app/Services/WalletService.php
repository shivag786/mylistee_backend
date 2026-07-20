<?php

namespace App\Services;

use App\Enums\RewardStatus;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Customer wallet (document/phase/07 §Wallet, phase/02 §Wallet). The wallet is
 * the customer's collection of won rewards — the `rewards` table is the single
 * source of truth, so the summary is computed rather than denormalized (avoids
 * balance drift). Every read first lazily expires any past-expiry rewards.
 */
class WalletService
{
    /** Flip active-but-past-expiry rewards to Expired before any read. */
    public function sweepExpired(User $customer): void
    {
        Reward::forCustomer($customer->id)->stale()->update([
            'status' => RewardStatus::Expired->value,
        ]);
    }

    /**
     * Reward counts by state (document/phase/07 §Wallet — available / redeemed /
     * expired / total).
     *
     * @return array<string, int>
     */
    public function summary(User $customer): array
    {
        $this->sweepExpired($customer);

        $counts = Reward::forCustomer($customer->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'available' => (int) ($counts[RewardStatus::Active->value] ?? 0),
            'redeemed' => (int) ($counts[RewardStatus::Redeemed->value] ?? 0),
            'expired' => (int) ($counts[RewardStatus::Expired->value] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * The customer's rewards, optionally filtered by status, newest first.
     *
     * @return Collection<int, Reward>
     */
    public function rewards(User $customer, ?RewardStatus $status = null): Collection
    {
        $this->sweepExpired($customer);

        return Reward::forCustomer($customer->id)
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->with('business')
            ->latest('won_at')
            ->get();
    }
}
