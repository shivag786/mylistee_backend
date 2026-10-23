<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tells a shop it has a new order: an in-app notification plus an FCM push to
 * every device the owner has switched alerts on for.
 *
 * One place for both ways an order arrives (placed for cash, or once its
 * online payment lands), so the alert always reads the same. The push goes out
 * after the order's transaction commits — never for an order that rolled back,
 * and never holding the transaction open while Firebase answers.
 */
class OrderAlertService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param  string|null  $paymentLine  e.g. "₹200 paid online in full." */
    public function newOrder(Order $order, ?string $paymentLine = null): void
    {
        $order->loadMissing(['business.owner', 'customer:id,name', 'items', 'diningTable']);
        $owner = $order->business?->owner;
        if ($owner === null) {
            return;
        }

        DB::afterCommit(fn () => $this->send($owner, $order, $paymentLine));
    }

    /** A sample alert, so an owner can check this phone rings. */
    public function test(User $owner): void
    {
        $this->notifications->notify(
            $owner,
            NotificationType::System,
            '🔔 Order alerts are on',
            'This is how a new order will reach this phone.',
            ['link' => '/business/orders', 'tag' => 'order-test', 'urgent' => '1'],
        );
    }

    private function send(User $owner, Order $order, ?string $paymentLine): void
    {
        $type = $order->service_type instanceof ServiceType ? $order->service_type : ServiceType::tryFrom((string) $order->service_type);
        $items = (int) $order->items->sum('quantity');
        $customer = trim((string) $order->customer?->name);

        // "Home delivery · ₹340 · 3 items · Priya"
        $summary = implode(' · ', array_filter([
            $type === ServiceType::Delivery ? 'Home delivery' : $order->serviceLabel(),
            '₹'.rtrim(rtrim(number_format((float) $order->total, 2, '.', ''), '0'), '.'),
            $items === 1 ? '1 item' : "{$items} items",
            $customer !== '' ? $customer : null,
        ]));

        $this->notifications->notify(
            $owner,
            NotificationType::OrderPlaced,
            "🛎️ New order {$order->token}",
            $paymentLine ? "{$summary}\n{$paymentLine}" : $summary,
            [
                'link' => '/business/orders',
                // One notification per order: a later push for it replaces this one.
                'tag' => "order-{$order->uuid}",
                // Stays on screen and vibrates until the owner acts on it.
                'urgent' => '1',
                'orderId' => (string) $order->uuid,
            ],
        );
    }
}
