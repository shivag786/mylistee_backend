<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderPaymentService;
use Illuminate\Console\Command;

/**
 * Withdraws orders whose online payment never arrived.
 *
 * The checkout releases an order itself when the customer closes the payment
 * window. That call never happens when the tab is killed instead, the phone
 * dies, or the network drops -- and the order would then sit in
 * AwaitingPayment for good, holding the coins spent on it.
 *
 * Releasing is safe even if the money turns up later: a capture on a released
 * order reinstates it and takes the coins back (OrderPaymentService::capture),
 * so the worst case is a short delay, never a lost payment.
 */
class ReleaseUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:release-unpaid {--minutes=30 : Release orders unpaid for longer than this}';

    protected $description = 'Withdraw online orders whose payment never arrived and return their coins.';

    public function handle(OrderPaymentService $payments): int
    {
        $minutes = max(5, (int) $this->option('minutes'));

        $released = 0;
        Order::where('status', OrderStatus::AwaitingPayment->value)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($payments, &$released): void {
                foreach ($orders as $order) {
                    if ($payments->release($order)->status === OrderStatus::Cancelled) {
                        $released++;
                    }
                }
            });

        $this->info("Released {$released} unpaid order(s).");

        return self::SUCCESS;
    }
}
