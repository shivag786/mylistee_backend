<?php

namespace App\Services\WhatsApp;

use App\Enums\ServiceType;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Order;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Listee's automatic WhatsApp messages — order updates to the customer and the
 * new-order alert to the shop. Each goes out only when the admin picked an
 * approved template for it (admin → WhatsApp → Auto messages); otherwise this
 * does nothing and the app's own push notifications carry on alone.
 *
 * Sending waits for the order's transaction to commit and then for the HTTP
 * response to go out, so a slow Interakt never slows down placing an order.
 */
class WhatsAppEventService
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
    ) {}

    /** The approved template set for an event, or null when it's not in use. */
    public function templateFor(string $event): ?WhatsAppTemplate
    {
        if (! $this->settings->active()) {
            return null;
        }
        $uuid = $this->settings->eventTemplate($event);
        if ($uuid === null) {
            return null;
        }

        $template = WhatsAppTemplate::query()->where('uuid', $uuid)->first();

        return $template?->isApproved() ? $template : null;
    }

    /** An order message: `order_placed_owner` goes to the shop, the rest to the customer. */
    public function order(string $event, Order $order): void
    {
        $template = $this->templateFor($event);
        if ($template === null) {
            return;
        }

        $order->loadMissing(['business.owner', 'customer:id,name,phone', 'items']);
        $business = $order->business;
        $phone = $event === 'order_placed_owner'
            ? ($business?->whatsapp ?: $business?->phone ?: $business?->owner?->phone)
            : $order->customer?->phone;
        if ($business === null || blank($phone)) {
            return;
        }

        $context = WhatsAppSender::shopContext($business) + $this->orderContext($order);
        DB::afterCommit(fn () => SendWhatsAppMessage::dispatchAfterResponse($template->id, (string) $phone, $event, $context, $business->id));
    }

    /** @return array<string, string|null> */
    private function orderContext(Order $order): array
    {
        $type = $order->service_type instanceof ServiceType ? $order->service_type : ServiceType::tryFrom((string) $order->service_type);
        $items = (int) $order->items->sum('quantity');

        return [
            'customer_name' => trim((string) ($order->customer?->name ?? '')) ?: null,
            'order_token' => (string) $order->token,
            'order_total' => '₹'.rtrim(rtrim(number_format((float) $order->total, 2, '.', ''), '0'), '.'),
            'order_items' => $items === 1 ? '1 item' : "{$items} items",
            'order_service' => $type === ServiceType::Delivery ? 'Home delivery' : $order->serviceLabel(),
        ];
    }
}
