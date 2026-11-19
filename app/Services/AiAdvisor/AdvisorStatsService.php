<?php

namespace App\Services\AiAdvisor;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the AI advisors work from. Only aggregates leave this class —
 * no customer names, phones or ids ever go to the AI.
 *
 * Weekday / hour grouping is done in PHP (business timezone) so it behaves the
 * same on MySQL and on SQLite in tests.
 */
class AdvisorStatsService
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Stats for the AI Sales Manager.
     *
     * @return array<string, mixed>
     */
    public function sales(Business $business): array
    {
        $orders = $this->orders($business, now()->subDays($this->window()));
        $orderIds = $orders->pluck('id')->all();
        $revenue = round((float) $orders->sum('total'), 2);
        $items = $this->items($orderIds);

        $byProduct = $items->where('item_type', 'product')->whereNotNull('product_id')
            ->groupBy('product_id')
            ->map(fn (Collection $rows, $pid) => [
                'id' => (int) $pid,
                'name' => (string) $rows->first()->name,
                'qty' => (int) $rows->sum('quantity'),
                'revenue' => round((float) $rows->sum(fn ($r) => $r->unit_price * $r->quantity), 2),
            ]);

        $catalog = $this->catalog($business);
        $top = $byProduct->sortByDesc('qty')->take(15)->values()->all();
        // Slowest sellers among what is on the menu now, including items that sold nothing.
        $bottom = collect($catalog)
            ->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => (int) ($byProduct[$p['id']]['qty'] ?? 0)])
            ->sortBy('qty')->take(10)->values()->all();

        return [
            'windowDays' => $this->window(),
            'orders' => $orders->count(),
            'revenue' => $revenue,
            'aov' => $orders->count() > 0 ? round($revenue / $orders->count(), 2) : 0,
            'topItems' => $top,
            'bottomItems' => $bottom,
            'byWeekday' => $this->byWeekday($orders),
            'byHour' => $this->byHour($orders),
            'topPairs' => $this->pairs($items),
            'activeOffers' => $this->activeOffers($business),
            'catalog' => $catalog,
        ];
    }

    /**
     * Stats for the AI Customer Growth Advisor.
     *
     * @return array<string, mixed>
     */
    public function growth(Business $business): array
    {
        $window = $this->window();
        $since = now()->subDays($window);
        $orders = $this->orders($business, $since);
        $known = $orders->whereNotNull('customer_id');
        $customerIds = $known->pluck('customer_id')->unique()->values();

        // First counted order ever and lifetime count, for the customers seen in the window.
        $history = $customerIds->isEmpty() ? collect() : Order::query()
            ->where('business_id', $business->id)
            ->whereIn('status', $this->statuses())
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'), DB::raw('COUNT(*) as n'))
            ->get()->keyBy('customer_id');

        $new = $history->filter(fn ($h) => Carbon::parse($h->first_at)->gte($since))->count();
        $repeat = $history->filter(fn ($h) => (int) $h->n >= 2)->count();
        $unique = $customerIds->count();

        $lapsedFrom = now()->subDays((int) config('ai_advisor.lapsed_from_days', 90));
        $lapsedTo = now()->subDays((int) config('ai_advisor.lapsed_to_days', 30));
        $recent = Order::query()->where('business_id', $business->id)->whereIn('status', $this->statuses())
            ->whereNotNull('customer_id')->where('created_at', '>=', $lapsedTo)->select('customer_id');
        $lapsed = Order::query()->where('business_id', $business->id)->whereIn('status', $this->statuses())
            ->whereNotNull('customer_id')
            ->whereBetween('created_at', [$lapsedFrom, $lapsedTo])
            ->whereNotIn('customer_id', $recent)
            ->distinct()->count('customer_id');

        $hours = $this->byHour($orders);
        $open = array_filter($hours, fn ($h) => $h['orders'] > 0);
        $peak = collect($open)->sortByDesc('orders')->take(3)->pluck('hour')->values()->all();
        // Slow = the quietest hours between the first and last hour that ever sees orders.
        $slow = [];
        if ($open !== []) {
            $first = min(array_column($open, 'hour'));
            $last = max(array_column($open, 'hour'));
            $slow = collect($hours)->filter(fn ($h) => $h['hour'] >= $first && $h['hour'] <= $last)
                ->sortBy('orders')->take(3)->pluck('hour')->values()->all();
        }

        $reviews = $business->reviews()->where('status', 'published');
        $reviewsRecent = (clone $reviews)->where('created_at', '>=', $since);

        return [
            'windowDays' => $window,
            'orders' => $orders->count(),
            'uniqueCustomers' => $unique,
            'newCustomers' => $new,
            'repeatCustomers' => $repeat,
            'repeatRatePercent' => $unique > 0 ? round($repeat / $unique * 100, 1) : 0,
            'walkInOrders' => $orders->whereNull('customer_id')->count(),
            'lapsedCustomers' => $lapsed,
            'followers' => DB::table('favorite_businesses')->where('business_id', $business->id)->count(),
            'peakHours' => $peak,
            'slowHours' => $slow,
            'byWeekday' => $this->byWeekday($orders),
            'rating' => [
                'average' => round((float) (clone $reviews)->avg('rating'), 2),
                'count' => (clone $reviews)->count(),
                'lastWindow' => (clone $reviewsRecent)->count(),
                'lowLastWindow' => (clone $reviewsRecent)->where('rating', '<=', 3)->count(),
            ],
            'topItems' => $this->sales($business)['topItems'] ?? [],
            'activeOffers' => $this->activeOffers($business),
        ];
    }

    /** @return list<array{id: int, name: string, price: float, category: ?string}> */
    public function catalog(Business $business): array
    {
        return $business->products()->with('category:id,name')
            ->where('is_visible', true)
            ->orderBy('position')->limit(120)
            ->get(['id', 'name', 'selling_price', 'product_category_id'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => (float) $p->selling_price,
                'category' => $p->category?->name,
            ])->all();
    }

    private function window(): int
    {
        return max(7, (int) config('ai_advisor.window_days', 30));
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return (array) config('ai_advisor.counted_statuses', ['paid', 'completed']);
    }

    /** @return Collection<int, Order> */
    private function orders(Business $business, Carbon $since): Collection
    {
        return Order::query()
            ->where('business_id', $business->id)
            ->whereIn('status', $this->statuses())
            ->where('created_at', '>=', $since)
            ->latest('id')->limit(5000)
            ->get(['id', 'customer_id', 'total', 'created_at']);
    }

    /** @param  list<int>  $orderIds */
    private function items(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return collect(array_chunk($orderIds, 1000))->flatMap(fn ($chunk) => DB::table('order_items')
            ->whereIn('order_id', $chunk)
            ->get(['order_id', 'product_id', 'item_type', 'name', 'unit_price', 'quantity']));
    }

    private function local(Carbon $at): Carbon
    {
        return $at->copy()->setTimezone(config('app.business_timezone', 'Asia/Kolkata'));
    }

    /** @return list<array{day: string, orders: int, revenue: float}> */
    private function byWeekday(Collection $orders): array
    {
        $out = [];
        foreach (self::WEEKDAYS as $d) {
            $out[$d] = ['day' => $d, 'orders' => 0, 'revenue' => 0.0];
        }
        foreach ($orders as $o) {
            $d = strtolower($this->local($o->created_at)->format('D'));
            $out[$d]['orders']++;
            $out[$d]['revenue'] = round($out[$d]['revenue'] + (float) $o->total, 2);
        }

        return array_values($out);
    }

    /** @return list<array{hour: int, orders: int, revenue: float}> */
    private function byHour(Collection $orders): array
    {
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[$h] = ['hour' => $h, 'orders' => 0, 'revenue' => 0.0];
        }
        foreach ($orders as $o) {
            $h = (int) $this->local($o->created_at)->format('G');
            $out[$h]['orders']++;
            $out[$h]['revenue'] = round($out[$h]['revenue'] + (float) $o->total, 2);
        }

        return array_values($out);
    }

    /** Items most often bought together (same order). @return list<array{a: string, b: string, aId: int, bId: int, orders: int}> */
    private function pairs(Collection $items): array
    {
        $counts = [];
        $names = [];
        foreach ($items->where('item_type', 'product')->whereNotNull('product_id')->groupBy('order_id') as $rows) {
            $ids = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
            foreach ($rows as $r) {
                $names[(int) $r->product_id] = (string) $r->name;
            }
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $key = $ids[$i].'-'.$ids[$j];
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return collect(array_slice($counts, 0, 10, true))->map(function ($c, $key) use ($names) {
            [$a, $b] = array_map('intval', explode('-', (string) $key));

            return ['aId' => $a, 'a' => $names[$a] ?? '', 'bId' => $b, 'b' => $names[$b] ?? '', 'orders' => $c];
        })->values()->all();
    }

    /** @return array{promotions: list<array<string, mixed>>, combos: list<array<string, mixed>>, coupons: list<array<string, mixed>>} */
    private function activeOffers(Business $business): array
    {
        return [
            'promotions' => $business->promotions()->with('product:id,name')
                ->whereIn('status', [PromotionStatus::Running->value, PromotionStatus::Scheduled->value])
                ->limit(20)->get()
                ->map(fn ($p) => [
                    'name' => $p->name,
                    'type' => $p->promotion_type?->value,
                    'product' => $p->product?->name,
                    'value' => $p->config['value'] ?? null,
                    'endsAt' => $p->ends_at?->toDateString(),
                ])->all(),
            'combos' => $business->combos()->where('is_visible', true)->limit(20)->get(['name', 'combo_price'])
                ->map(fn ($c) => ['name' => $c->name, 'price' => (float) $c->combo_price])->all(),
            'coupons' => Coupon::query()->where('business_id', $business->id)->where('kind', Coupon::KIND_CODE)->where('active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->limit(20)->get(['code', 'title'])
                ->map(fn ($c) => ['code' => $c->code, 'title' => $c->title])->all(),
        ];
    }
}
