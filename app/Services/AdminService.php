<?php

namespace App\Services;

use App\Enums\BusinessStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RewardStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessVisit;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Reward;
use App\Models\Spin;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide aggregates for the Super Admin dashboard, growth graph and fraud
 * signals (document/phase/14 §Admin Dashboard / §Fraud Detection). Read-only.
 */
class AdminService
{
    /**
     * Headline dashboard payload (document/phase/14 §Dashboard Widgets).
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $today = Carbon::today();
        $paid = fn (Builder $q): Builder => $q->where('status', InvoiceStatus::Paid->value);

        return [
            'stats' => [
                'totalCustomers' => User::where('role', UserRole::Customer->value)->count(),
                'activeCustomers' => User::where('role', UserRole::Customer->value)
                    ->where('status', UserStatus::Active->value)->count(),
                'totalBusinesses' => Business::count(),
                'verifiedBusinesses' => Business::where('verified', true)->count(),
                'pendingBusinesses' => Business::where('status', BusinessStatus::Pending->value)->count(),
                'activeOffers' => Offer::query()->countsAsActive()->count(),
                'spinsToday' => Spin::whereDate('created_at', $today)->count(),
                'visitsToday' => BusinessVisit::whereDate('created_at', $today)->count(),
                'redemptionsToday' => Reward::where('status', RewardStatus::Redeemed->value)
                    ->whereDate('redeemed_at', $today)->count(),
                'activeSubscriptions' => Subscription::query()->active()
                    ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                    ->count(),
                'revenueTotal' => (float) $paid(Invoice::query())->sum('amount'),
                'revenueThisMonth' => (float) $paid(Invoice::query())
                    ->whereYear('issued_at', $today->year)
                    ->whereMonth('issued_at', $today->month)
                    ->sum('amount'),
                'pendingApprovals' => Business::where('status', BusinessStatus::Pending->value)->count(),
            ],
            'growth' => $this->growth(30),
            'health' => $this->health(),
        ];
    }

    /**
     * Daily new customers and businesses over the window (zero-filled).
     *
     * @return list<array{date: string, customers: int, businesses: int}>
     */
    public function growth(int $days = 30): array
    {
        $to = Carbon::today()->endOfDay();
        $from = Carbon::today()->subDays($days - 1)->startOfDay();

        $customers = $this->dailyCounts(
            User::where('role', UserRole::Customer->value)->whereBetween('created_at', [$from, $to]),
            $from,
            $to,
        );
        $businesses = $this->dailyCounts(
            Business::whereBetween('created_at', [$from, $to]),
            $from,
            $to,
        );

        $points = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $points[] = [
                'date' => $key,
                'customers' => $customers[$key] ?? 0,
                'businesses' => $businesses[$key] ?? 0,
            ];
        }

        return $points;
    }

    /**
     * Fraud signals (document/phase/14 §Fraud Detection). Read-only heuristics —
     * surfaces suspicious patterns for a human to review, never auto-acts.
     *
     * @return array<string, mixed>
     */
    public function fraud(): array
    {
        $since = Carbon::now()->subDays(7);

        // Excessive spins: a customer spinning far more than the 1/business/day norm.
        $excessiveSpins = Spin::query()
            ->where('spins.created_at', '>=', $since)
            ->join('users', 'users.id', '=', 'spins.customer_id')
            ->groupBy('spins.customer_id', 'users.name', 'users.email')
            ->havingRaw('COUNT(*) >= 15')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(20)
            ->get([
                'spins.customer_id',
                'users.name',
                'users.email',
                DB::raw('COUNT(*) as spins'),
            ])
            ->map(fn ($r): array => [
                'name' => $r->name,
                'email' => $r->email,
                'spins' => (int) $r->spins,
                'risk' => $r->spins >= 30 ? 'high' : 'medium',
            ])->all();

        // Shared IPs: one IP tied to several accounts → possible multi-account abuse.
        $sharedIps = Spin::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT customer_id) >= 3')
            ->orderByRaw('COUNT(DISTINCT customer_id) DESC')
            ->limit(20)
            ->get([
                'ip_address',
                DB::raw('COUNT(DISTINCT customer_id) as accounts'),
                DB::raw('COUNT(*) as spins'),
            ])
            ->map(fn ($r): array => [
                'ip' => $r->ip_address,
                'accounts' => (int) $r->accounts,
                'spins' => (int) $r->spins,
                'risk' => $r->accounts >= 5 ? 'high' : 'medium',
            ])->all();

        return [
            'summary' => [
                'excessiveSpins' => count($excessiveSpins),
                'sharedIps' => count($sharedIps),
                'highRisk' => collect($excessiveSpins)->where('risk', 'high')->count()
                    + collect($sharedIps)->where('risk', 'high')->count(),
            ],
            'excessiveSpins' => $excessiveSpins,
            'sharedIps' => $sharedIps,
        ];
    }

    /**
     * Lightweight system-health probe (document/phase/14 §System Health).
     *
     * @return array<string, string>
     */
    private function health(): array
    {
        $db = 'ok';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $db = 'down';
        }

        return [
            'database' => $db,
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function dailyCounts(Builder $query, Carbon $from, Carbon $to): array
    {
        return $query
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->map(static fn ($c): int => (int) $c)
            ->all();
    }
}
