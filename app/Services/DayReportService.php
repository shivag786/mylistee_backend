<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Business;
use App\Models\CashMovement;
use App\Models\DayClosing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The day-end report and the cash count.
 *
 * A business day runs from the shop's day-start hour (default 4 am) to the
 * same hour next day, so a late-night restaurant's 1 am bills stay on the
 * evening they belong to. Sales are counted by when the bill was paid.
 *
 * Money in the drawer: yesterday's "cash left" + cash bills + cash put in −
 * cash taken out = what should be there. The owner (or a cashier) counts it
 * and closes the day; a difference beyond the owner's limit alerts them.
 */
class DayReportService
{
    public function __construct(private readonly OwnerAlertService $alerts) {}

    /** @return array<string, mixed> */
    public function report(Business $business, string $date): array
    {
        [$from, $to] = $business->businessDayWindow($date);
        $zone = config('app.business_timezone', 'Asia/Kolkata');

        $paid = $business->orders()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Completed->value])
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->with('items')
            ->get();
        $cancelled = $business->orders()
            ->where('status', OrderStatus::Cancelled->value)
            ->where('cancelled_at', '>=', $from)->where('cancelled_at', '<', $to)
            ->get();
        $open = $business->orders()
            ->whereIn('status', [OrderStatus::Placed->value, OrderStatus::Confirmed->value])
            ->where('placed_at', '<', $to)
            ->where('placed_at', '>=', $from->copy()->subDay())
            ->get();

        $sum = fn (string $col) => round((float) $paid->sum(fn ($o) => (float) $o->{$col}), 2);
        $total = $sum('total');
        $tax = round($sum('cgst') + $sum('sgst'), 2);

        $payments = $this->payments($paid);
        $cash = $this->cash($business, $date, $payments['cash']);
        $closing = DayClosing::where('business_id', $business->id)->whereDate('business_date', $date)->with('closer:id,name')->first();

        // Same weekday last week, to say how today went.
        [$lwFrom, $lwTo] = $business->businessDayWindow(Carbon::parse($date)->subWeek()->toDateString());
        $lastWeek = $business->orders()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Completed->value])
            ->where('paid_at', '>=', $lwFrom)->where('paid_at', '<', $lwTo)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as amount')->first();

        return [
            'date' => $date,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'dayStartHour' => $business->posSettings()->day_start_hour,
            'sales' => [
                'orders' => $paid->count(),
                'gross' => $sum('subtotal'),
                'discounts' => [
                    'shop' => $sum('bill_discount'),
                    'coupon' => $sum('coupon_discount'),
                    'manual' => $sum('manual_discount'),
                    'coins' => $sum('coin_discount'),
                    'total' => round($sum('bill_discount') + $sum('coupon_discount') + $sum('manual_discount') + $sum('coin_discount'), 2),
                ],
                'serviceCharge' => $sum('service_charge'),
                'deliveryFees' => $sum('delivery_fee'),
                'cgst' => $sum('cgst'),
                'sgst' => $sum('sgst'),
                'tax' => $tax,
                'taxable' => $sum('taxable_amount'),
                'roundOff' => $sum('round_off'),
                'total' => $total,
                'netExTax' => round($total - $tax, 2),
                'averageBill' => $paid->count() > 0 ? round($total / $paid->count(), 2) : 0.0,
                'invoices' => [
                    'first' => $paid->whereNotNull('invoice_no')->sortBy('paid_at')->first()?->invoice_no,
                    'last' => $paid->whereNotNull('invoice_no')->sortBy('paid_at')->last()?->invoice_no,
                ],
            ],
            'lastWeek' => ['orders' => (int) ($lastWeek->n ?? 0), 'total' => round((float) ($lastWeek->amount ?? 0), 2)],
            'payments' => $payments['rows'],
            'services' => $paid->groupBy(fn ($o) => ($o->service_type instanceof ServiceType ? $o->service_type : (ServiceType::tryFrom((string) $o->service_type) ?? ServiceType::default()))->label())
                ->map(fn (Collection $g, $label) => ['label' => $label, 'orders' => $g->count(), 'amount' => round((float) $g->sum('total'), 2)])
                ->values()->all(),
            'sources' => [
                'counter' => ['orders' => $paid->where('source', 'counter')->count(), 'amount' => round((float) $paid->where('source', 'counter')->sum('total'), 2)],
                'app' => ['orders' => $paid->where('source', '!=', 'counter')->count(), 'amount' => round((float) $paid->where('source', '!=', 'counter')->sum('total'), 2)],
            ],
            'topItems' => $paid->flatMap->items
                ->groupBy('name')
                ->map(fn (Collection $g, $name) => [
                    'name' => $name,
                    'quantity' => (int) $g->sum('quantity'),
                    'amount' => round((float) $g->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity), 2),
                ])
                ->sortByDesc('quantity')->take(10)->values()->all(),
            'hourly' => $paid->groupBy(fn ($o) => (int) $o->paid_at->copy()->timezone($zone)->format('G'))
                ->map(fn (Collection $g, $hour) => ['hour' => (int) $hour, 'orders' => $g->count(), 'amount' => round((float) $g->sum('total'), 2)])
                ->sortBy('hour')->values()->all(),
            'staff' => $this->byStaff($paid, $cancelled),
            'cancelled' => [
                'orders' => $cancelled->count(),
                'amount' => round((float) $cancelled->sum('total'), 2),
                'list' => $cancelled->sortByDesc('cancelled_at')->take(30)->map(fn (Order $o) => [
                    'id' => $o->uuid,
                    'token' => $o->token,
                    'amount' => (float) $o->total,
                    'reason' => $o->cancel_reason,
                    'by' => $o->cancelled_by ? User::whereKey($o->cancelled_by)->value('name') : null,
                    'at' => $o->cancelled_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'openBills' => [
                'orders' => $open->count(),
                'amount' => round((float) $open->sum('total'), 2),
            ],
            'cash' => $cash,
            'closing' => $closing ? $this->closingPayload($closing) : null,
        ];
    }

    /**
     * Close the day: the counted cash against what should be there, kept with
     * the report as it stood. Closing again (a recount) replaces the count.
     *
     * @return array<string, mixed> the report, with the closing
     */
    public function close(Business $business, string $date, float $counted, ?float $cashLeft, ?string $note, User $actor): array
    {
        if (Carbon::parse($date)->greaterThan(Carbon::parse($business->businessDateOf()))) {
            throw ValidationException::withMessages(['date' => ['You can\'t close a day that hasn\'t started.']]);
        }

        $report = $this->report($business, $date);
        $expected = (float) $report['cash']['expected'];
        $difference = round($counted - $expected, 2);
        $left = $cashLeft !== null ? max(0.0, min($cashLeft, $counted)) : $counted;

        $closing = DayClosing::where('business_id', $business->id)->whereDate('business_date', $date)->first()
            ?? new DayClosing(['business_id' => $business->id, 'business_date' => $date]);
        $closing->fill([
                'opening_cash' => $report['cash']['opening'],
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $difference,
                'cash_left' => $left,
                'total_sales' => $report['sales']['total'],
                'orders_count' => $report['sales']['orders'],
                'summary' => array_diff_key($report, ['closing' => true]),
                'note' => $note,
                'closed_by' => $actor->id,
        ])->save();

        $limit = (float) ($business->posSettings()->alerts()['cashDifferenceAmount'] ?? 100);
        if (abs($difference) > $limit) {
            $this->alerts->raise(
                $business,
                'cash_difference',
                'Cash '.($difference < 0 ? 'short' : 'extra').' by '.OwnerAlertService::money(abs($difference)).' · '.Carbon::parse($date)->format('d M'),
                'Expected '.OwnerAlertService::money($expected).', counted '.OwnerAlertService::money($counted)." by {$actor->name}.".($note ? " Note: {$note}" : ''),
                'danger',
                ['date' => $date],
                $actor,
                'cash:'.$date.':'.$closing->updated_at?->timestamp,
            );
        }
        $this->summaryAlert($business, $date, $report, closedBy: $actor);

        return $this->report($business, $date);
    }

    /**
     * The day's sales pushed to the owner — at their summary time, or when the
     * day is closed, whichever comes first.
     *
     * @param  array<string, mixed>|null  $report
     */
    public function summaryAlert(Business $business, string $date, ?array $report = null, ?User $closedBy = null): void
    {
        $report ??= $this->report($business, $date);
        $s = $report['sales'];
        if ($s['orders'] === 0 && $closedBy === null) {
            return;
        }
        $pay = collect($report['payments'])->filter(fn ($p) => $p['amount'] > 0)
            ->map(fn ($p) => $p['label'].' '.OwnerAlertService::money($p['amount']))->join(' · ');
        $change = $report['lastWeek']['total'] > 0
            ? ' ('.($s['total'] >= $report['lastWeek']['total'] ? '▲' : '▼').' '.abs((int) round(($s['total'] - $report['lastWeek']['total']) / $report['lastWeek']['total'] * 100)).'% vs last week)'
            : '';

        $this->alerts->raise(
            $business,
            'daily_summary',
            ($closedBy ? 'Day closed' : 'Today so far').': '.OwnerAlertService::money($s['total']).' from '.$s['orders'].' '.($s['orders'] === 1 ? 'bill' : 'bills').$change,
            trim(($pay !== '' ? $pay.'. ' : '')
                .($s['tax'] > 0 ? 'GST '.OwnerAlertService::money($s['tax']).'. ' : '')
                .($s['discounts']['manual'] > 0 ? 'Staff discounts '.OwnerAlertService::money($s['discounts']['manual']).'. ' : '')
                .($report['cancelled']['orders'] > 0 ? $report['cancelled']['orders'].' cancelled ('.OwnerAlertService::money($report['cancelled']['amount']).'). ' : '')
                .($closedBy ? "Closed by {$closedBy->name}." : '')),
            'info',
            ['date' => $date],
            $closedBy,
            'summary:'.$date.($closedBy ? ':closed' : ''),
        );
    }

    /** Cash in or out of the drawer, on today's business day. */
    public function addMovement(Business $business, string $type, float $amount, string $reason, User $actor): CashMovement
    {
        $date = $business->businessDateOf();
        if (DayClosing::where('business_id', $business->id)->whereDate('business_date', $date)->exists()) {
            throw ValidationException::withMessages(['date' => ['Today is already closed. Reopen it by closing again after this entry.']]);
        }

        return CashMovement::create([
            'business_id' => $business->id,
            'business_date' => $date,
            'type' => $type,
            'amount' => round($amount, 2),
            'reason' => $reason,
            'user_id' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    public function movementPayload(CashMovement $m): array
    {
        return [
            'id' => $m->uuid,
            'type' => $m->type,
            'amount' => (float) $m->amount,
            'reason' => $m->reason,
            'by' => $m->user?->name,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function closingPayload(DayClosing $c): array
    {
        return [
            'id' => $c->uuid,
            'date' => $c->business_date->toDateString(),
            'openingCash' => (float) $c->opening_cash,
            'expectedCash' => (float) $c->expected_cash,
            'countedCash' => (float) $c->counted_cash,
            'difference' => (float) $c->difference,
            'cashLeft' => (float) $c->cash_left,
            'totalSales' => (float) $c->total_sales,
            'orders' => $c->orders_count,
            'note' => $c->note,
            'closedBy' => $c->closer?->name,
            'closedAt' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Where the money came from: cash, UPI, card at the counter, and what
     * customers paid online through the app.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{rows: list<array{method: string, label: string, orders: int, amount: float}>, cash: float}
     */
    private function payments(Collection $orders): array
    {
        $buckets = [
            'cash' => ['label' => 'Cash', 'orders' => 0, 'amount' => 0.0],
            'upi' => ['label' => 'UPI', 'orders' => 0, 'amount' => 0.0],
            'card' => ['label' => 'Card', 'orders' => 0, 'amount' => 0.0],
            'online' => ['label' => 'Other online', 'orders' => 0, 'amount' => 0.0],
            'app' => ['label' => 'Paid in app', 'orders' => 0, 'amount' => 0.0],
        ];

        foreach ($orders as $order) {
            $gateway = $order->isPaidOnline() ? min((float) $order->online_amount, (float) $order->total) : 0.0;
            $counter = round((float) $order->total - $gateway, 2);
            if ($gateway > 0) {
                $buckets['app']['orders']++;
                $buckets['app']['amount'] += $gateway;
            }
            if ($counter > 0) {
                $method = $order->payment_method instanceof PaymentMethod ? $order->payment_method->value : (string) ($order->payment_method ?? 'cod');
                $key = match ($method) {
                    'upi' => 'upi',
                    'card' => 'card',
                    'online' => 'online',
                    default => 'cash',
                };
                $buckets[$key]['orders']++;
                $buckets[$key]['amount'] += $counter;
            }
        }

        $rows = [];
        foreach ($buckets as $method => $b) {
            if ($b['orders'] > 0 || in_array($method, ['cash', 'upi', 'card'], true)) {
                $rows[] = ['method' => $method, 'label' => $b['label'], 'orders' => $b['orders'], 'amount' => round($b['amount'], 2)];
            }
        }

        return ['rows' => $rows, 'cash' => round($buckets['cash']['amount'], 2)];
    }

    /** @return array<string, mixed> */
    private function cash(Business $business, string $date, float $cashSales): array
    {
        $previous = DayClosing::where('business_id', $business->id)
            ->whereDate('business_date', '<', $date)
            ->orderByDesc('business_date')->first();
        $opening = (float) ($previous?->cash_left ?? 0);

        $movements = CashMovement::where('business_id', $business->id)->whereDate('business_date', $date)->with('user:id,name')->oldest()->get();
        $in = round((float) $movements->where('type', 'in')->sum('amount'), 2);
        $out = round((float) $movements->where('type', 'out')->sum('amount'), 2);

        return [
            'opening' => $opening,
            'sales' => $cashSales,
            'in' => $in,
            'out' => $out,
            'expected' => round($opening + $cashSales + $in - $out, 2),
            'movements' => $movements->map(fn ($m) => $this->movementPayload($m))->values()->all(),
        ];
    }

    /**
     * Who did what today: bills taken, money collected (and how much of it
     * cash), discounts given, orders cancelled.
     *
     * @param  Collection<int, Order>  $paid
     * @param  Collection<int, Order>  $cancelled
     * @return list<array<string, mixed>>
     */
    private function byStaff(Collection $paid, Collection $cancelled): array
    {
        $rows = [];
        $row = function (?int $id) use (&$rows): ?string {
            if ($id === null) {
                return null;
            }
            $rows[$id] ??= ['userId' => $id, 'taken' => 0, 'takenAmount' => 0.0, 'collected' => 0, 'collectedAmount' => 0.0, 'cash' => 0.0, 'discount' => 0.0, 'cancelled' => 0, 'cancelledAmount' => 0.0];

            return (string) $id;
        };

        foreach ($paid as $o) {
            if ($row($o->created_by) !== null) {
                $rows[$o->created_by]['taken']++;
                $rows[$o->created_by]['takenAmount'] += (float) $o->total;
            }
            if ($row($o->paid_by) !== null) {
                $rows[$o->paid_by]['collected']++;
                $rows[$o->paid_by]['collectedAmount'] += (float) $o->total;
                $method = $o->payment_method instanceof PaymentMethod ? $o->payment_method->value : $o->payment_method;
                if ($method === null || $method === 'cod') {
                    $rows[$o->paid_by]['cash'] += (float) $o->total - ($o->isPaidOnline() ? (float) $o->online_amount : 0.0);
                }
            }
            if ($row($o->discount_by) !== null) {
                $rows[$o->discount_by]['discount'] += (float) $o->manual_discount;
            }
        }
        foreach ($cancelled as $o) {
            if ($row($o->cancelled_by) !== null) {
                $rows[$o->cancelled_by]['cancelled']++;
                $rows[$o->cancelled_by]['cancelledAmount'] += (float) $o->total;
            }
        }

        $users = User::whereIn('id', array_keys($rows))->get(['id', 'name', 'role'])->keyBy('id');

        return collect($rows)->map(fn ($r) => [
            'name' => $users[$r['userId']]->name ?? 'Unknown',
            'isOwner' => ($users[$r['userId']]->role->value ?? null) === 'business_owner',
            'taken' => $r['taken'],
            'takenAmount' => round($r['takenAmount'], 2),
            'collected' => $r['collected'],
            'collectedAmount' => round($r['collectedAmount'], 2),
            'cash' => round(max(0.0, $r['cash']), 2),
            'discount' => round($r['discount'], 2),
            'cancelled' => $r['cancelled'],
            'cancelledAmount' => round($r['cancelledAmount'], 2),
        ])->sortByDesc('collectedAmount')->values()->all();
    }
}
