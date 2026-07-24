<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Order;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order lifecycle (Phase 7.5). A customer confirms a one-shop cart into an
 * order; the owner confirms, marks it paid (manual — no gateway), and completes
 * it. Wallet coins may be spent at checkout and are earned back on payment.
 */
class OrderService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * Place an order. `$items` is `[{type: 'product'|'combo', id: uuid, quantity}]`.
     * Prices are snapshotted from the current effective price / combo price.
     *
     * @param  array<int, array{type: string, id: string, quantity?: int}>  $items
     *
     * @throws ValidationException
     */
    public function place(Business $business, User $customer, array $items, int $coinsToUse = 0, ?string $note = null): Order
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Your cart is empty.']]);
        }

        return DB::transaction(function () use ($business, $customer, $items, $coinsToUse, $note): Order {
            $lines = [];
            $subtotal = 0.0;
            $coinsEarned = 0;
            // Coins are a combo perk: the most a customer can spend is the sum of
            // each combo's owner-set "accept up to N coins", times its quantity.
            $comboCoinCap = 0;

            foreach ($items as $item) {
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                if (($item['type'] ?? null) === 'combo') {
                    $combo = $business->combos()->where('uuid', $item['id'])->first();
                    if ($combo === null || ! $combo->is_visible) {
                        throw ValidationException::withMessages(['items' => ['A combo in your cart is no longer available.']]);
                    }
                    $unit = (float) $combo->combo_price;
                    $earn = (int) ($combo->coins_earned ?? 0);
                    $coinsEarned += $earn * $quantity;
                    $comboCoinCap += (int) ($combo->coins_accepted ?? 0) * $quantity;
                    $lines[] = [
                        'combo_id' => $combo->id,
                        'item_type' => 'combo',
                        'name' => $combo->name,
                        'unit_price' => $unit,
                        'quantity' => $quantity,
                        'coins_earned' => $earn,
                    ];
                } else {
                    $product = $business->products()->where('uuid', $item['id'])->with('promotions')->first();
                    if ($product === null || ! $product->is_visible || ! $product->in_stock) {
                        throw ValidationException::withMessages(['items' => ['A product in your cart is no longer available.']]);
                    }
                    $unit = $product->effectivePrice();
                    $lines[] = [
                        'product_id' => $product->id,
                        'item_type' => 'product',
                        'name' => $product->name,
                        'unit_price' => $unit,
                        'quantity' => $quantity,
                        'coins_earned' => 0,
                    ];
                }

                $subtotal += $unit * $quantity;
            }

            $subtotal = round($subtotal, 2);

            // Apply wallet coins — capped by the balance, the subtotal's worth, and
            // what the cart's combos accept (0 ⇒ no coins can be spent on this order).
            $coinValue = max(1, (int) config('loyalty.coin_value', 1));
            $balance = $this->loyalty->balanceForBusiness($customer, $business);
            $maxCoins = (int) min($balance, floor($subtotal / $coinValue), $comboCoinCap);
            $coinsUsed = max(0, min($coinsToUse, $maxCoins));
            $coinDiscount = round($coinsUsed * $coinValue, 2);

            $order = Order::create([
                'token' => $this->uniqueToken($business),
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'status' => OrderStatus::Placed,
                'subtotal' => $subtotal,
                'coins_used' => $coinsUsed,
                'coin_discount' => $coinDiscount,
                'total' => round($subtotal - $coinDiscount, 2),
                'coins_earned' => $coinsEarned,
                'note' => $note,
                'placed_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            if ($coinsUsed > 0) {
                $this->loyalty->spend($customer, $coinsUsed, $business, $order, "Paid with coins on order {$order->token}");
            }

            if ($business->owner) {
                $this->notifications->notify(
                    $business->owner,
                    NotificationType::OrderPlaced,
                    "New order {$order->token}",
                    "₹{$order->total} · {$order->items()->count()} item(s).",
                    ['link' => '/business/orders'],
                );
            }

            return $order->load('items');
        });
    }

    /**
     * Move an order to the next state. Enforces the allowed transitions.
     *
     * @throws ValidationException
     */
    public function transition(Order $order, OrderStatus $to, User $actor): Order
    {
        $allowed = match ($order->status) {
            OrderStatus::Placed => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            OrderStatus::Confirmed => [OrderStatus::Paid, OrderStatus::Cancelled],
            OrderStatus::Paid => [OrderStatus::Completed],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Can't move this order from {$order->status->value} to {$to->value}."],
            ]);
        }

        return DB::transaction(function () use ($order, $to, $actor): Order {
            match ($to) {
                OrderStatus::Confirmed => $order->update(['status' => $to, 'confirmed_at' => Carbon::now()]),
                OrderStatus::Paid => $this->markPaid($order, $actor),
                OrderStatus::Completed => $order->update(['status' => $to, 'completed_at' => Carbon::now()]),
                OrderStatus::Cancelled => $this->markCancelled($order),
                default => null,
            };

            return $order->fresh('items');
        });
    }

    private function markPaid(Order $order, User $actor): void
    {
        $order->update([
            'status' => OrderStatus::Paid,
            'paid_at' => Carbon::now(),
            'paid_by' => $actor->id,
        ]);

        // Credit the coins the combos promised.
        if ($order->coins_earned > 0 && $order->customer) {
            $this->loyalty->award($order->customer, CoinSource::OrderEarn, $order->business, [
                'amount' => $order->coins_earned,
                'reference' => $order,
                'description' => "Order {$order->token} reward",
            ]);
        }

        // Mint any next-visit coupons the combos offered.
        $this->mintCoupons($order);

        if ($order->customer) {
            $coins = $order->coins_earned > 0 ? " You earned {$order->coins_earned} coins." : '';
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Payment received ✅',
                "Your order {$order->token} at {$order->business->name} is paid.{$coins}",
                ['link' => '/wallet'],
            );
        }
    }

    private function markCancelled(Order $order): void
    {
        $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => Carbon::now()]);

        // Return any coins spent on the order.
        if ($order->coins_used > 0 && $order->customer) {
            $this->loyalty->refund($order->customer, $order->coins_used, $order->business, $order, "Refund for cancelled order {$order->token}");
        }

        if ($order->customer) {
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Order cancelled',
                "Your order {$order->token} at {$order->business->name} was cancelled.",
                ['link' => '/wallet'],
            );
        }
    }

    /** Create a reward coupon for each combo in the order that offers one. */
    private function mintCoupons(Order $order): void
    {
        if (! $order->customer) {
            return;
        }

        $comboIds = $order->items->whereNotNull('combo_id')->pluck('combo_id')->unique();
        $combos = Combo::whereIn('id', $comboIds)->whereNotNull('next_visit_coupon')->get();

        foreach ($combos as $combo) {
            Reward::create([
                'customer_id' => $order->customer_id,
                'business_id' => $order->business_id,
                'offer_id' => null,
                'title' => $combo->next_visit_coupon,
                'reward_value' => $combo->next_visit_coupon,
                'type' => 'coupon',
                'status' => RewardStatus::Active,
                'won_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDays(30),
            ]);
        }
    }

    /** A short numeric token unique among the business's active orders. */
    private function uniqueToken(Business $business): string
    {
        do {
            $token = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $exists = $business->orders()
                ->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()))
                ->where('token', $token)
                ->exists();
        } while ($exists);

        return $token;
    }
}
