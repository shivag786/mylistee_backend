<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Subscription;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Owner-panel insight pages built on data Listee already has:
 * subscriptions (who pays, who is about to lapse), vendor health (shops that
 * need a nudge) and QR scans (which posters work).
 */
class InsightsController extends Controller
{
    /** GET /admin/subscriptions?status=active|expiring|expired|cancelled|all&search= */
    public function subscriptions(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'expiring', 'expired', 'cancelled', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $v['status'] ?? 'active';
        $now = now();
        $live = fn (Builder $q) => $q->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));

        $page = Subscription::query()
            ->with(['business:id,uuid,name,slug,phone,owner_id', 'business.owner:id,name,phone', 'plan:id,key,name'])
            ->when($status === 'active', $live)
            ->when($status === 'expiring', fn ($q) => $q->where('status', 'active')->whereBetween('ends_at', [$now, $now->copy()->addDays(7)]))
            ->when($status === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('status', 'expired')
                ->orWhere(fn ($x) => $x->where('status', 'active')->where('ends_at', '<=', $now))))
            ->when($status === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->when(! empty($v['search']), fn ($q) => $q->whereHas('business', fn ($b) => $b->where('name', 'like', '%'.addcslashes($v['search'], '%_\\').'%')))
            ->orderByRaw($status === 'expiring' ? 'ends_at asc' : 'id desc')
            ->paginate(25, page: $v['page'] ?? 1);

        $paidShops = Subscription::query()->tap($live)->distinct()->count('business_id');

        return ApiResponse::success([
            'summary' => [
                'active' => Subscription::query()->tap($live)->count(),
                'expiringWeek' => Subscription::query()->where('status', 'active')->whereBetween('ends_at', [$now, $now->copy()->addDays(7)])->count(),
                'expiredMonth' => Subscription::query()->where(fn ($w) => $w->where('status', 'expired')->orWhere('status', 'active'))
                    ->whereBetween('ends_at', [$now->copy()->subDays(30), $now])->count(),
                'freeShops' => max(0, Business::query()->where('status', 'active')->count() - $paidShops),
            ],
            'items' => collect($page->items())->map(fn (Subscription $s) => [
                'id' => $s->uuid,
                'shop' => $s->business?->name,
                'shopId' => $s->business?->uuid,
                'owner' => $s->business?->owner?->name,
                'phone' => $s->business?->owner?->phone ?? $s->business?->phone,
                'plan' => $s->plan?->name,
                'price' => (float) $s->price,
                'interval' => $s->interval,
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
                'autoRenew' => (bool) $s->auto_renew,
                'startsAt' => $s->starts_at?->toIso8601String(),
                'endsAt' => $s->ends_at?->toIso8601String(),
                'daysLeft' => $s->ends_at ? (int) floor($now->diffInDays($s->ends_at, false)) : null,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ], 'Subscriptions retrieved.');
    }

    /** The checks behind vendor health: key => [label, SQL that is true when the shop has the problem]. */
    private function healthChecks(): array
    {
        $since = now()->subDays(14)->toDateTimeString();

        return [
            'no_logo' => ['No logo', 'businesses.logo_path IS NULL'],
            'no_products' => ['No menu / products', 'NOT EXISTS (SELECT 1 FROM products p WHERE p.business_id = businesses.id)'],
            'no_offer' => ['No live offer', "NOT EXISTS (SELECT 1 FROM offers o WHERE o.business_id = businesses.id AND o.status = 'active')"],
            'no_location' => ['No map location', 'businesses.latitude IS NULL OR businesses.longitude IS NULL'],
            'no_app' => ['Owner app not installed', 'NOT EXISTS (SELECT 1 FROM device_tokens d WHERE d.user_id = businesses.owner_id)'],
            'no_scans' => ['No QR scans in 14 days', "NOT EXISTS (SELECT 1 FROM business_visits v WHERE v.business_id = businesses.id AND v.created_at >= '{$since}')"],
            'no_orders' => ['No orders in 14 days', "NOT EXISTS (SELECT 1 FROM orders r WHERE r.business_id = businesses.id AND r.created_at >= '{$since}')"],
        ];
    }

    /** GET /admin/vendor-health?issue=&search=&page= — active shops with what they are missing. */
    public function vendorHealth(Request $request): JsonResponse
    {
        $checks = $this->healthChecks();
        $v = $request->validate([
            'issue' => ['nullable', Rule::in(array_keys($checks))],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $select = ['businesses.id', 'businesses.uuid', 'businesses.name', 'businesses.slug', 'businesses.city', 'businesses.owner_id', 'businesses.category_id'];
        $flags = [];
        foreach ($checks as $key => [, $sql]) {
            $flags[] = "CASE WHEN ({$sql}) THEN 1 ELSE 0 END AS f_{$key}";
        }
        $issueSum = implode(' + ', array_map(fn ($k) => "CASE WHEN ({$checks[$k][1]}) THEN 1 ELSE 0 END", array_keys($checks)));

        $base = Business::query()->where('businesses.status', 'active');

        $page = (clone $base)
            ->with(['owner:id,name,phone', 'category:id,name'])
            ->select($select)
            ->selectRaw(implode(', ', $flags))
            ->selectRaw("({$issueSum}) AS issue_count")
            ->when(! empty($v['issue']), fn ($q) => $q->whereRaw($checks[$v['issue']][1]))
            ->when(! empty($v['search']), fn ($q) => $q->where('businesses.name', 'like', '%'.addcslashes($v['search'], '%_\\').'%'))
            ->orderByDesc('issue_count')->orderBy('businesses.name')
            ->paginate(25, page: $v['page'] ?? 1);

        $counts = [];
        foreach ($checks as $key => [$label, $sql]) {
            $counts[] = ['key' => $key, 'label' => $label, 'count' => (clone $base)->whereRaw($sql)->count()];
        }

        return ApiResponse::success([
            'total' => (clone $base)->count(),
            'issues' => $counts,
            'items' => collect($page->items())->map(function (Business $b) use ($checks) {
                $issues = [];
                foreach ($checks as $key => [$label]) {
                    if ((int) $b->getAttribute("f_{$key}") === 1) {
                        $issues[] = ['key' => $key, 'label' => $label];
                    }
                }

                return [
                    'id' => $b->uuid,
                    'name' => $b->name,
                    'slug' => $b->slug,
                    'city' => $b->city,
                    'category' => $b->category?->name,
                    'owner' => $b->owner?->name,
                    'phone' => $b->owner?->phone,
                    // 100 = nothing missing.
                    'score' => (int) round(100 * (count($checks) - count($issues)) / count($checks)),
                    'issues' => $issues,
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ], 'Vendor health retrieved.');
    }

    /** GET /admin/qr-scans?days=30 — scans per day, top shops, by category and city. */
    public function qrScans(Request $request): JsonResponse
    {
        $days = (int) $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 30, 90])]])['days'] ?: 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $visits = fn () => DB::table('business_visits')->where('business_visits.created_at', '>=', $from);

        $perDay = $visits()->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $d, 'scans' => (int) ($perDay[$d] ?? 0)];
        }

        $top = $visits()
            ->join('businesses', 'businesses.id', '=', 'business_visits.business_id')
            ->leftJoin('business_categories', 'business_categories.id', '=', 'businesses.category_id')
            ->selectRaw('businesses.uuid as id, businesses.name, businesses.city, business_categories.name as category, COUNT(*) as scans, COUNT(DISTINCT business_visits.customer_id) as customers')
            ->groupBy('businesses.uuid', 'businesses.name', 'businesses.city', 'business_categories.name')
            ->orderByDesc('scans')->limit(20)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'city' => $r->city, 'category' => $r->category, 'scans' => (int) $r->scans, 'customers' => (int) $r->customers]);

        $by = fn (string $col, string $label) => $visits()
            ->join('businesses', 'businesses.id', '=', 'business_visits.business_id')
            ->leftJoin('business_categories', 'business_categories.id', '=', 'businesses.category_id')
            ->selectRaw("COALESCE({$col}, 'Unknown') as label, COUNT(*) as scans")
            ->groupBy('label')->orderByDesc('scans')->limit(12)->get()
            ->map(fn ($r) => [$label => $r->label, 'scans' => (int) $r->scans]);

        $activeShops = DB::table('businesses')->where('status', 'active')->count();
        $scannedShops = $visits()->distinct()->count('business_id');

        return ApiResponse::success([
            'days' => $days,
            'totalScans' => $visits()->count(),
            'uniqueCustomers' => $visits()->whereNotNull('customer_id')->distinct()->count('customer_id'),
            'shopsScanned' => $scannedShops,
            'shopsNotScanned' => max(0, $activeShops - $scannedShops),
            'allTimeScans' => (int) DB::table('qr_codes')->sum('scan_count'),
            'series' => $series,
            'topShops' => $top,
            'byCategory' => $by('business_categories.name', 'category'),
            'byCity' => $by('businesses.city', 'city'),
        ], 'QR scans retrieved.');
    }
}
