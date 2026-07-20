<?php

namespace App\Services;

use App\Enums\RewardStatus;
use App\Models\Business;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Business analytics (document/phase/02 §Business Dashboard / §Analytics,
 * phase/07 §Analytics). Aggregates visits, spins, rewards and redemptions into
 * a period summary (with trend vs. the previous period), a zero-filled daily
 * time series, and a top-offers table. Read-only; no side effects.
 */
class AnalyticsService
{
    /** Allowed look-back windows (days) for the range selector. */
    public const RANGES = [7, 30, 90];

    /**
     * @return array<string, mixed>
     */
    public function forBusiness(Business $business, int $days = 30): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;

        $to = Carbon::today()->endOfDay();
        $from = Carbon::today()->subDays($days - 1)->startOfDay();
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subSecond();

        $redeemed = static fn (HasMany $q): HasMany => $q->where('status', RewardStatus::Redeemed->value);

        // Current period
        $visits = $business->visits()->whereBetween('created_at', [$from, $to])->count();
        $spins = $business->spins()->whereBetween('created_at', [$from, $to])->count();
        $rewards = $business->rewards()->whereBetween('won_at', [$from, $to])->count();
        $redemptions = $redeemed($business->rewards())->whereBetween('redeemed_at', [$from, $to])->count();

        // Previous period (for trend arrows)
        $prevVisits = $business->visits()->whereBetween('created_at', [$prevFrom, $prevTo])->count();
        $prevSpins = $business->spins()->whereBetween('created_at', [$prevFrom, $prevTo])->count();
        $prevRewards = $business->rewards()->whereBetween('won_at', [$prevFrom, $prevTo])->count();
        $prevRedemptions = $redeemed($business->rewards())->whereBetween('redeemed_at', [$prevFrom, $prevTo])->count();

        $uniqueCustomers = $business->spins()->whereBetween('created_at', [$from, $to])
            ->distinct('customer_id')->count('customer_id');

        // Repeat-customer rate is measured all-time — a returning customer is the
        // core success metric of the platform (phase/02 §Business Principles).
        $allCustomers = $business->spins()->distinct('customer_id')->count('customer_id');
        $repeatCustomers = $business->spins()
            ->select('customer_id')->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')->get()->count();

        return [
            'range' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => Carbon::today()->toDateString(),
            ],
            'summary' => [
                'visits' => $this->metric($visits, $prevVisits),
                'spins' => $this->metric($spins, $prevSpins),
                'rewards' => $this->metric($rewards, $prevRewards),
                'redemptions' => $this->metric($redemptions, $prevRedemptions),
                'uniqueCustomers' => $uniqueCustomers,
                'repeatCustomerRate' => $this->rate($repeatCustomers, $allCustomers),
                'spinConversionRate' => $this->rate($spins, $visits),
                'redemptionRate' => $this->rate($redemptions, $rewards),
            ],
            'series' => $this->series($business, $from, $to),
            'topOffers' => $this->topOffers($business, $from, $to),
        ];
    }

    /**
     * Zero-filled daily buckets across the window.
     *
     * @return list<array{date: string, visits: int, spins: int, rewards: int, redemptions: int}>
     */
    private function series(Business $business, Carbon $from, Carbon $to): array
    {
        $visits = $this->daily($business->visits(), 'created_at', $from, $to);
        $spins = $this->daily($business->spins(), 'created_at', $from, $to);
        $rewards = $this->daily($business->rewards(), 'won_at', $from, $to);
        $redemptions = $this->daily(
            $business->rewards()->where('status', RewardStatus::Redeemed->value),
            'redeemed_at',
            $from,
            $to,
        );

        $points = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $points[] = [
                'date' => $key,
                'visits' => $visits[$key] ?? 0,
                'spins' => $spins[$key] ?? 0,
                'rewards' => $rewards[$key] ?? 0,
                'redemptions' => $redemptions[$key] ?? 0,
            ];
        }

        return $points;
    }

    /**
     * Count rows per calendar day for a relation, keyed by Y-m-d.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\HasMany<*, *>  $query
     * @return array<string, int>
     */
    private function daily(HasMany $query, string $column, Carbon $from, Carbon $to): array
    {
        return $query->whereBetween($column, [$from, $to])
            ->selectRaw("DATE({$column}) as d, COUNT(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd')
            ->map(static fn ($c): int => (int) $c)
            ->all();
    }

    /**
     * Best-performing offers by rewards won in the window.
     *
     * @return list<array<string, mixed>>
     */
    private function topOffers(Business $business, Carbon $from, Carbon $to): array
    {
        return $business->offers()
            ->withCount([
                'rewards as rewards_count' => fn ($q) => $q->whereBetween('won_at', [$from, $to]),
                'rewards as redemptions_count' => fn ($q) => $q
                    ->where('status', RewardStatus::Redeemed->value)
                    ->whereBetween('redeemed_at', [$from, $to]),
            ])
            ->orderByDesc('rewards_count')
            ->limit(5)
            ->get()
            ->map(fn ($offer): array => [
                'uuid' => $offer->uuid,
                'title' => $offer->title,
                'type' => $offer->type instanceof \App\Enums\OfferType ? $offer->type->value : $offer->type,
                'rewards' => (int) $offer->rewards_count,
                'redemptions' => (int) $offer->redemptions_count,
                'redemptionRate' => $this->rate((int) $offer->redemptions_count, (int) $offer->rewards_count),
            ])
            ->filter(fn (array $o): bool => $o['rewards'] > 0)
            ->values()
            ->all();
    }

    /**
     * A metric value paired with its previous-period value and % change.
     *
     * @return array{value: int, previous: int, changePct: float|null}
     */
    private function metric(int $value, int $previous): array
    {
        $changePct = match (true) {
            $previous > 0 => round(($value - $previous) / $previous * 100, 1),
            $value > 0 => 100.0,
            default => 0.0,
        };

        return ['value' => $value, 'previous' => $previous, 'changePct' => $changePct];
    }

    /** Percentage of $part over $whole, rounded to 1 dp (0 when $whole is 0). */
    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
