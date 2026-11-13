<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Services\AttendanceService;
use App\Services\DayReportService;
use App\Services\OwnerAlertService;
use App\Services\PlanAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Time-based owner alerts, every few minutes:
 *  - a customer's order still not accepted after the owner's limit,
 *  - staff not checked in after shift start + grace,
 *  - the day's sales at the owner's summary time.
 *
 * Each alert is raised once (OwnerAlertService dedupes by key).
 */
class PosAlertsTick extends Command
{
    protected $signature = 'pos:alerts';

    protected $description = 'Owner alerts that depend on the time: waiting orders, late staff, daily summary';

    public function handle(OwnerAlertService $alerts, AttendanceService $attendance, DayReportService $reports, PlanAccessService $access): int
    {
        $zone = config('app.business_timezone', 'Asia/Kolkata');
        $since = now()->subDays(2);

        // Shops with something going on: recent orders, or staff.
        $ids = Business::query()
            ->where(fn ($q) => $q->whereHas('orders', fn ($o) => $o->where('created_at', '>=', $since))
                ->orWhereHas('staff', fn ($s) => $s->where('active', true)))
            ->pluck('id');

        foreach (Business::whereIn('id', $ids)->with('owner')->cursor() as $business) {
            try {
                $rules = $business->posSettings()->alerts();

                // Customer orders waiting to be accepted.
                $minutes = max(1, (int) $rules['orderWaitingMinutes']);
                $waiting = $business->orders()
                    ->where('status', OrderStatus::Placed->value)
                    ->where('placed_at', '<=', now()->subMinutes($minutes))
                    ->where('placed_at', '>=', now()->subHours(3))
                    ->get();
                foreach ($waiting as $order) {
                    $alerts->raise(
                        $business,
                        'order_waiting',
                        "Order {$order->token} waiting {$minutes}+ min",
                        'A customer\'s order of '.OwnerAlertService::money((float) $order->total).' is not accepted yet.',
                        'danger',
                        ['orderId' => $order->uuid],
                        dedupeKey: 'waiting:'.$order->uuid,
                    );
                }

                if ($access->allows($business, 'attendance')) {
                    $attendance->lateCheck($business);
                }

                // The day's sales, once, at the summary time.
                $now = Carbon::now($zone);
                if ($now->format('H:i') >= (string) $rules['dailySummaryTime']) {
                    $reports->summaryAlert($business, $business->businessDateOf());
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $access->forget($business);
        }

        return self::SUCCESS;
    }
}
