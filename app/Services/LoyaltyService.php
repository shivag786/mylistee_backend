<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Enums\CoinTransactionType;
use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Reward;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Listee Coins engine (Phase 2 — flagship). Owns every coin movement: earning
 * from platform events and (later slices) spending on reward tiers. The ledger
 * (wallet_transactions) is the single source of truth — a balance is always the
 * SUM of its rows, never a stored number that could drift.
 *
 * Earn rates come from config/loyalty.php today; a per-business loyalty_programs
 * override is layered on in a later slice without changing this contract.
 */
class LoyaltyService
{
    /**
     * Whether coins are being granted. The global config switch is the master
     * kill-switch; a business can additionally disable its own program.
     */
    public function isEnabled(?Business $business = null): bool
    {
        if (! (bool) config('loyalty.enabled', true)) {
            return false;
        }

        if ($business !== null) {
            $program = $business->loyaltyProgram;
            if ($program !== null && ! $program->enabled) {
                return false;
            }
        }

        return true;
    }

    /**
     * Grant coins for an earning event. Returns the ledger entry, or null when
     * nothing was granted (loyalty off, zero rate, or the business hit its cap) —
     * callers treat coins as a best-effort bonus, never a hard dependency.
     *
     * @param  array{amount?: int, reference?: Model|null, description?: string, meta?: array<string, mixed>}  $options
     */
    public function award(User $user, CoinSource $source, ?Business $business = null, array $options = []): ?WalletTransaction
    {
        if (! $this->isEnabled($business)) {
            return null;
        }

        $amount = $options['amount'] ?? $this->earnRate($source, $business);

        if ($amount <= 0) {
            return null;
        }

        if ($business !== null && $this->exceedsBudget($business, $amount)) {
            return null;
        }

        return $this->record(
            $user,
            CoinTransactionType::Earn,
            $source,
            $amount,
            $business,
            $options['reference'] ?? null,
            $options['description'] ?? $source->label(),
            $options['meta'] ?? null,
        );
    }

    /**
     * Award coins only if this user has never earned from this source at this
     * business (idempotent). Used for one-time bonuses like the first-scan or
     * review reward.
     *
     * @param  array{amount?: int, reference?: Model|null, description?: string, meta?: array<string, mixed>}  $options
     */
    public function awardOnce(User $user, CoinSource $source, ?Business $business = null, array $options = []): ?WalletTransaction
    {
        if ($this->hasEarned($user, $source, $business)) {
            return null;
        }

        return $this->award($user, $source, $business, $options);
    }

    /**
     * Award coins only if this user hasn't already earned from this source today
     * (e.g. the daily check-in). "Today" is calendar-day based.
     *
     * @param  array{amount?: int, reference?: Model|null, description?: string, meta?: array<string, mixed>}  $options
     */
    public function awardOncePerDay(User $user, CoinSource $source, ?Business $business = null, array $options = []): ?WalletTransaction
    {
        if ($this->hasEarned($user, $source, $business, Carbon::today())) {
            return null;
        }

        return $this->award($user, $source, $business, $options);
    }

    /** Whether the user has an earn entry for this source (optionally since a time). */
    public function hasEarned(User $user, CoinSource $source, ?Business $business = null, ?Carbon $since = null): bool
    {
        return $user->walletTransactions()
            ->where('source', $source->value)
            ->when($business !== null, fn ($q) => $q->where('business_id', $business->id))
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }

    /** Current Listee Coins balance (sum of all ledger rows). */
    public function balanceFor(User $user): int
    {
        return (int) $user->walletTransactions()->sum('amount');
    }

    /** Coins earned/spent with one specific business. */
    public function balanceForBusiness(User $user, Business $business): int
    {
        return (int) $user->walletTransactions()
            ->where('business_id', $business->id)
            ->sum('amount');
    }

    /**
     * The coin amount for an earning source. A business's loyalty_programs
     * override wins when set; otherwise the platform default in config applies.
     */
    public function earnRate(CoinSource $source, ?Business $business = null): int
    {
        $key = $source->earnKey();
        if ($key === null) {
            return 0;
        }

        if ($business !== null) {
            $program = $business->loyaltyProgram;
            $column = $source->programColumn();
            if ($program !== null && $column !== null && $program->{$column} !== null) {
                return (int) $program->{$column};
            }
        }

        return (int) config("loyalty.earn.{$key}", 0);
    }

    /**
     * Write one ledger row inside a serialized transaction so the running
     * `balance_after` is consistent even under concurrent awards for a user.
     */
    private function record(
        User $user,
        CoinTransactionType $type,
        CoinSource $source,
        int $amount,
        ?Business $business,
        ?Model $reference,
        ?string $description,
        ?array $meta,
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $type, $source, $amount, $business, $reference, $description, $meta): WalletTransaction {
            // Lock this user's rows so two concurrent awards can't compute the
            // same balance_after.
            $current = (int) $user->walletTransactions()->lockForUpdate()->sum('amount');

            return $user->walletTransactions()->create([
                'business_id' => $business?->id,
                'type' => $type,
                'source' => $source,
                'amount' => $amount,
                'balance_after' => $current + $amount,
                'description' => $description,
                'reference_type' => $reference ? $reference->getMorphClass() : null,
                'reference_id' => $reference?->getKey(),
                'meta' => $meta,
            ]);
        });
    }

    /**
     * True when granting `$amount` would push the business past its monthly coin
     * budget cap (0 = unlimited). Sourced from config today; a per-business cap
     * overrides it in a later slice.
     */
    private function exceedsBudget(Business $business, int $amount): bool
    {
        $cap = (int) ($business->loyaltyProgram?->monthly_budget_cap
            ?? config('loyalty.monthly_budget_cap', 0));

        if ($cap <= 0) {
            return false;
        }

        $mintedThisMonth = (int) WalletTransaction::query()
            ->where('business_id', $business->id)
            ->where('type', CoinTransactionType::Earn->value)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('amount');

        return ($mintedThisMonth + $amount) > $cap;
    }

    // -- Owner configuration -------------------------------------------------

    /**
     * The business's effective loyalty settings (stored overrides resolved
     * against config defaults) for the owner config screen.
     *
     * @return array<string, mixed>
     */
    public function describeProgram(Business $business): array
    {
        $program = $business->loyaltyProgram;

        return [
            'enabled' => $program?->enabled ?? true,
            'coinsPerSpin' => $this->earnRate(CoinSource::Spin, $business),
            'coinsPerFirstScan' => $this->earnRate(CoinSource::FirstScan, $business),
            'coinsPerCheckin' => $this->earnRate(CoinSource::Checkin, $business),
            'coinsPerReview' => $this->earnRate(CoinSource::Review, $business),
            'coinsPerRedeem' => $this->earnRate(CoinSource::Redeem, $business),
            'monthlyBudgetCap' => (int) ($program?->monthly_budget_cap ?? config('loyalty.monthly_budget_cap', 0)),
            'coinsMintedThisMonth' => (int) WalletTransaction::query()
                ->where('business_id', $business->id)
                ->where('type', CoinTransactionType::Earn->value)
                ->where('created_at', '>=', Carbon::now()->startOfMonth())
                ->sum('amount'),
        ];
    }

    /**
     * Create or update the business's loyalty program.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProgram(Business $business, array $data): LoyaltyProgram
    {
        return LoyaltyProgram::updateOrCreate(
            ['business_id' => $business->id],
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createReward(Business $business, array $data): LoyaltyReward
    {
        return $business->loyaltyRewards()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateReward(LoyaltyReward $reward, array $data): LoyaltyReward
    {
        $reward->update($data);

        return $reward->fresh();
    }

    public function deleteReward(LoyaltyReward $reward): void
    {
        $reward->delete();
    }

    // -- Customer wallet + spending -----------------------------------------

    /**
     * Total balance + a per-business breakdown for the customer's coin wallet.
     *
     * @return array{total: int, businesses: array<int, array<string, mixed>>}
     */
    public function coinSummary(User $user): array
    {
        /** @var \Illuminate\Support\Collection<int, int> $grouped */
        $grouped = $user->walletTransactions()
            ->whereNotNull('business_id')
            ->groupBy('business_id')
            ->selectRaw('business_id, SUM(amount) as balance')
            ->pluck('balance', 'business_id');

        $businesses = Business::query()
            ->whereIn('id', $grouped->keys()->all())
            ->get()
            ->keyBy('id');

        $breakdown = $grouped
            ->filter(fn ($balance) => (int) $balance > 0)
            ->map(function ($balance, $businessId) use ($businesses) {
                $business = $businesses->get($businessId);
                if ($business === null) {
                    return null;
                }

                return [
                    'businessId' => $business->uuid,
                    'businessName' => $business->name,
                    'slug' => $business->slug,
                    'logoUrl' => $business->logo_path
                        ? Storage::disk('public')->url($business->logo_path)
                        : null,
                    'balance' => (int) $balance,
                ];
            })
            ->filter()
            ->sortByDesc('balance')
            ->values()
            ->all();

        return [
            'total' => $this->balanceFor($user),
            'businesses' => $breakdown,
        ];
    }

    /**
     * The customer's most recent ledger entries (newest first).
     *
     * @return Collection<int, WalletTransaction>
     */
    public function transactions(User $user, int $limit = 50): Collection
    {
        return $user->walletTransactions()
            ->with('business')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /** Active reward tiers a customer can spend coins on at a business. @return Collection<int, LoyaltyReward> */
    public function availableRewards(Business $business): Collection
    {
        return $business->loyaltyRewards()->active()->get();
    }

    /**
     * Redeem coins for a reward tier. Debits the ledger and mints a normal
     * reward code the owner scans through the existing redemption flow — atomic,
     * with a stock lock and a balance check under the same transaction.
     *
     * @throws ValidationException when unavailable or the balance is too low
     */
    public function redeemTier(User $customer, LoyaltyReward $tier): Reward
    {
        return DB::transaction(function () use ($customer, $tier): Reward {
            /** @var LoyaltyReward|null $locked */
            $locked = LoyaltyReward::whereKey($tier->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isAvailable()) {
                throw ValidationException::withMessages([
                    'reward' => ['This reward is no longer available.'],
                ]);
            }

            $cost = $locked->coins_cost;
            $balance = (int) $customer->walletTransactions()->lockForUpdate()->sum('amount');

            if ($balance < $cost) {
                throw ValidationException::withMessages([
                    'coins' => ["You need {$cost} coins to redeem this — you have {$balance}."],
                ]);
            }

            $business = $locked->business;

            if ($locked->stock !== null) {
                $locked->decrement('stock');
            }

            $reward = Reward::create([
                'customer_id' => $customer->id,
                'business_id' => $business->id,
                'offer_id' => null,
                'title' => $locked->title,
                'reward_value' => $locked->reward_value ?: $locked->title,
                'type' => 'loyalty',
                'status' => RewardStatus::Active,
                'won_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDays(30),
            ]);

            $customer->walletTransactions()->create([
                'business_id' => $business->id,
                'type' => CoinTransactionType::Spend,
                'source' => CoinSource::TierRedeem,
                'amount' => -$cost,
                'balance_after' => $balance - $cost,
                'description' => "Redeemed {$locked->title}",
                'reference_type' => $reward->getMorphClass(),
                'reference_id' => $reward->getKey(),
            ]);

            return $reward;
        });
    }

    /**
     * Spend coins on an order (Phase 7.5). Row-locked balance check prevents
     * overspending under concurrency. Returns the ledger entry.
     *
     * @throws ValidationException
     */
    public function spend(User $user, int $coins, Business $business, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        return DB::transaction(function () use ($user, $coins, $business, $reference, $description): WalletTransaction {
            $balance = (int) $user->walletTransactions()->lockForUpdate()->sum('amount');

            if ($coins <= 0 || $balance < $coins) {
                throw ValidationException::withMessages([
                    'coins' => ["You only have {$balance} coins to spend."],
                ]);
            }

            return $user->walletTransactions()->create([
                'business_id' => $business->id,
                'type' => CoinTransactionType::Spend,
                'source' => CoinSource::OrderSpend,
                'amount' => -$coins,
                'balance_after' => $balance - $coins,
                'description' => $description ?? 'Paid with coins',
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /** Refund previously spent order coins (e.g. on cancellation). */
    public function refund(User $user, int $coins, Business $business, ?Model $reference = null, ?string $description = null): ?WalletTransaction
    {
        if ($coins <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $coins, $business, $reference, $description): WalletTransaction {
            $balance = (int) $user->walletTransactions()->lockForUpdate()->sum('amount');

            return $user->walletTransactions()->create([
                'business_id' => $business->id,
                'type' => CoinTransactionType::Adjust,
                'source' => CoinSource::OrderSpend,
                'amount' => $coins,
                'balance_after' => $balance + $coins,
                'description' => $description ?? 'Coins refunded',
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }
}
