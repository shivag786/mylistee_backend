<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Enums\KitchenStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RewardStatus;
use App\Enums\ServiceType;
use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Combo;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reward;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order lifecycle (Phase 7.5). A customer confirms a one-shop cart into an
 * order; the owner confirms, marks it paid, and completes it. Wallet coins may
 * be spent at checkout and are earned back on payment.
 *
 * An order paid online is placed in AwaitingPayment and handed to
 * OrderPaymentService; it reaches the shop only once the money lands.
 */
class OrderService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoyaltyService $loyalty,
        private readonly OrderPaymentService $payments,
        private readonly StockService $stock,
        private readonly RewardService $rewards,
        private readonly PlatformFeeService $fees,
        private readonly OrderAlertService $alerts,
        private readonly WhatsAppEventService $whatsapp,
        private readonly TaxService $tax,
        private readonly OwnerAlertService $ownerAlerts,
        private readonly ModuleUsageService $usage,
    ) {}

    /**
     * Place an order. `$items` is `[{type: 'product'|'combo', id: uuid, quantity}]`.
     * Prices are snapshotted from the current effective price / combo price.
     *
     * The service context (mode / table / address) is an additive layer: it never
     * changes how items, coins, or the token work — only how the order is served.
     *
     * @param  array<int, array{type: string, id: string, quantity?: int, variant?: string|null, addons?: list<string>|null}>  $items
     *
     * @throws ValidationException
     */
    public function place(
        Business $business,
        User $customer,
        array $items,
        int $coinsToUse = 0,
        ?string $note = null,
        ?ServiceType $serviceType = null,
        ?string $tableUuid = null,
        ?string $serviceAddress = null,
        ?string $paymentChoice = null,
        ?string $couponCode = null,
        ?array $delivery = null,
    ): Order {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Your cart is empty.']]);
        }

        if ($business->isTemporarilyClosed()) {
            $until = $business->closed_until->timezone(config('app.business_timezone'))->format('d M, h:i A');
            throw ValidationException::withMessages(['business' => ["{$business->name} is closed until {$until}."]]);
        }
        if ($business->orders_paused) {
            throw ValidationException::withMessages(['business' => ["{$business->name} is not taking orders right now. Please try again a little later."]]);
        }

        [$serviceType, $table, $serviceAddress] = $this->resolveService($business, $serviceType, $tableUuid, $serviceAddress);
        $delivery = $serviceType === ServiceType::Delivery ? $this->resolveDelivery($customer, $delivery ?? []) : [];
        $deliveryFee = $serviceType === ServiceType::Delivery ? $business->deliveryFee() : 0.0;

        // No choice sent means a client from before online payment existed: cash,
        // exactly as every order was until now. split() refuses it with a clear
        // message if this shop no longer takes cash.
        $paymentChoice ??= OrderPaymentService::CHOICE_COD;

        return DB::transaction(function () use ($business, $customer, $items, $coinsToUse, $note, $serviceType, $table, $serviceAddress, $deliveryFee, $paymentChoice, $couponCode, $delivery): Order {
            $priced = $this->priceItems($business, $items);
            $subtotal = $priced['subtotal'];

            // The shop's automatic bill discount, then the one coupon applied.
            ['billDiscount' => $billDiscount, 'coupon' => $coupon, 'couponDiscount' => $couponDiscount]
                = $this->discounts($business, $customer, $subtotal, $couponCode);
            $discounted = round($subtotal - $billDiscount - $couponDiscount, 2);

            // Apply wallet coins — capped by the balance, the bill's worth, and
            // what the cart's combos accept (0 ⇒ no coins can be spent on this order).
            $coinValue = max(1, (int) config('loyalty.coin_value', 1));
            $balance = $this->loyalty->balanceForBusiness($customer, $business);
            $maxCoins = (int) min($balance, floor($discounted / $coinValue), $priced['comboCoinCap']);
            $coinsUsed = max(0, min($coinsToUse, $maxCoins));
            $coinDiscount = round($coinsUsed * $coinValue, 2);

            // GST, service charge and round-off, when the shop bills that way.
            $bill = $this->tax->compute($business, self::taxLines($priced['lines']), $billDiscount + $couponDiscount + $coinDiscount, $deliveryFee, $this->tax->modeFor($business));
            $total = $bill['total'];
            ['online' => $online, 'fee' => $fee] = $this->payments->split($business, $total, $paymentChoice);
            // Listee's cut comes out of the shop's share, not the customer's bill.
            $fees = $this->fees->split($total, $online);

            // Anything to collect online holds the order back until it lands.
            $awaitingPayment = $online > 0;

            // Refuses the order (with "Only N left") when a counted product is short.
            $stockTaken = $this->stock->deduct($priced['stockNeeds']);

            $order = Order::create([
                'token' => $this->uniqueToken($business),
                // Numbered when it reaches the shop (an online order: once paid).
                'serial' => $awaitingPayment ? null : Order::nextSerialFor($business->id),
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'table_id' => $table?->id,
                'status' => $awaitingPayment ? OrderStatus::AwaitingPayment : OrderStatus::Placed,
                'payment_choice' => $paymentChoice,
                'online_amount' => $online,
                'convenience_fee' => $fee,
                'platform_fee' => $fees['platform'],
                'gateway_fee' => $fees['gateway'],
                'vendor_earning' => $fees['vendor'],
                'service_type' => $serviceType,
                'subtotal' => $subtotal,
                'bill_discount' => $billDiscount,
                'coupon_id' => $coupon?->id,
                'coupon_discount' => $couponDiscount,
                'coins_used' => $coinsUsed,
                'coin_discount' => $coinDiscount,
                'total' => $total,
                'delivery_fee' => $deliveryFee,
                'coins_earned' => $priced['coinsEarned'],
                'stock_deducted' => $stockTaken === [] ? null : $stockTaken,
                'note' => $note,
                'service_address' => $serviceAddress,
                'delivery_phone' => $delivery['phone'] ?? null,
                'delivery_lat' => $delivery['lat'] ?? null,
                'delivery_lng' => $delivery['lng'] ?? null,
                'placed_at' => Carbon::now(),
            ] + TaxService::orderColumns($bill));

            foreach (self::withTax($priced['lines'], $bill) as $line) {
                $order->items()->create($line);
            }

            if ($coupon !== null) {
                $this->rewards->redeem($coupon, $order, $customer, $couponDiscount);
            }

            if ($coinsUsed > 0) {
                $this->loyalty->spend($customer, $coinsUsed, $business, $order, "Paid with coins on order {$order->token}");
            }

            // An order waiting on its online payment is not an order for the shop
            // yet -- OrderPaymentService notifies them when the money lands.
            if (! $awaitingPayment) {
                $this->alerts->newOrder($order);
            }

            return $order->load('items');
        });
    }

    /**
     * A walk-in order the shop takes at the counter. Priced like any order (the
     * shop's bill discount applies); no coins, and the name said at the counter
     * goes in `customer_label`. The customer's mobile is kept on the order and,
     * when it belongs to a Listee account, links the order to it so the shop's
     * coupons for that customer can be applied and loyalty counts the visit.
     * The shop took it, so it starts accepted and goes to the kitchen; paid
     * straight away when [$paidWith] is given. Taken even with online orders
     * paused — the customer is standing there.
     *
     * @param  array<int, array{type: string, id: string, quantity?: int, variant?: string|null, addons?: list<string>|null}>  $items
     *
     * @throws ValidationException
     */
    public function placeAtCounter(
        Business $business,
        User $actor,
        array $items,
        ?ServiceType $serviceType = null,
        ?string $tableUuid = null,
        ?string $note = null,
        ?string $customerLabel = null,
        ?string $serviceAddress = null,
        ?PaymentMethod $paidWith = null,
        ?string $customerPhone = null,
        ?string $couponCode = null,
        ?array $discount = null,
    ): Order {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Add at least one item.']]);
        }

        [$serviceType, $table, $serviceAddress] = $this->resolveService($business, $serviceType, $tableUuid, $serviceAddress);
        $deliveryFee = $serviceType === ServiceType::Delivery ? $business->deliveryFee() : 0.0;
        $phone = $this->counterPhone($customerPhone);
        $customer = $this->customerByPhone($phone);

        $order = DB::transaction(function () use ($business, $actor, $items, $serviceType, $table, $serviceAddress, $deliveryFee, $note, $customerLabel, $phone, $customer, $couponCode, $discount): Order {
            $priced = $this->priceItems($business, $items);
            $subtotal = $priced['subtotal'];
            ['billDiscount' => $billDiscount, 'coupon' => $coupon, 'couponDiscount' => $couponDiscount]
                = $this->discounts($business, $customer, $subtotal, $couponCode);
            $manual = $this->manualDiscount($actor, round($subtotal - $billDiscount - $couponDiscount, 2), $discount);
            $bill = $this->tax->compute($business, self::taxLines($priced['lines']), $billDiscount + $couponDiscount + $manual['amount'], $deliveryFee, $this->tax->modeFor($business));
            $total = $bill['total'];
            $fees = $this->fees->split($total, 0.0);
            $stockTaken = $this->stock->deduct($priced['stockNeeds']);
            $now = Carbon::now();

            $order = Order::create([
                'token' => $this->uniqueToken($business),
                'serial' => Order::nextSerialFor($business->id),
                'business_id' => $business->id,
                'customer_id' => $customer?->id,
                'source' => 'counter',
                'customer_label' => $customerLabel !== null && trim($customerLabel) !== '' ? trim($customerLabel) : null,
                'table_id' => $table?->id,
                'status' => OrderStatus::Confirmed,
                'kitchen_status' => $business->usesKitchen() ? KitchenStatus::Queued : null,
                'payment_choice' => OrderPaymentService::CHOICE_COD,
                'online_amount' => 0,
                'convenience_fee' => 0,
                'platform_fee' => $fees['platform'],
                'gateway_fee' => $fees['gateway'],
                'vendor_earning' => $fees['vendor'],
                'service_type' => $serviceType,
                'subtotal' => $subtotal,
                'bill_discount' => $billDiscount,
                'coupon_id' => $coupon?->id,
                'coupon_discount' => $couponDiscount,
                'coins_used' => 0,
                'coin_discount' => 0,
                'total' => $total,
                'delivery_fee' => $deliveryFee,
                'coins_earned' => 0,
                'stock_deducted' => $stockTaken === [] ? null : $stockTaken,
                'note' => $note,
                'service_address' => $serviceAddress,
                'delivery_phone' => $phone,
                'placed_at' => $now,
                'confirmed_at' => $now,
                'created_by' => $actor->id,
                'manual_discount' => $manual['amount'],
                'manual_discount_percent' => $manual['percent'],
                'discount_reason' => $manual['reason'],
                'discount_by' => $manual['amount'] > 0 ? $actor->id : null,
            ] + TaxService::orderColumns($bill));

            foreach (self::withTax($priced['lines'], $bill) as $line) {
                $order->items()->create($line);
            }

            if ($manual['amount'] > 0) {
                $this->discountAlert($order, $actor);
            }

            if ($coupon !== null) {
                if ($customer !== null) {
                    $this->rewards->redeem($coupon, $order, $customer, $couponDiscount);
                } else {
                    $coupon->increment('used_count');
                }
            }

            return $order;
        });

        if ($paidWith !== null) {
            $order = $this->transition($order, OrderStatus::Paid, $actor, $paidWith);
        }

        return $order->load('items');
    }

    /**
     * The counter cart priced for the customer at the counter: their coupons
     * at this shop (picked out by mobile) and what one would save.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function counterQuote(Business $business, array $items, ?string $customerPhone, ?string $couponCode, ?ServiceType $serviceType): array
    {
        $customer = $this->customerByPhone($this->counterPhone($customerPhone));

        return $this->quote($business, $customer, $items, $couponCode, $serviceType) + [
            'customer' => $customer ? ['name' => $customer->name, 'registered' => true] : null,
        ];
    }

    /** A 10-digit Indian mobile from what was typed at the counter, or null. */
    private function counterPhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    private function customerByPhone(?string $phone): ?User
    {
        return $phone === null ? null : User::where('phone', $phone)->first();
    }

    /**
     * The shop changes an order still in play (new or accepted): moves it to
     * another table, changes quantities (0 removes a line) and adds items.
     * The bill is worked out again -- bill discount, coupon, coins -- and stock
     * follows the change. An order paid online can't drop below what was paid.
     *
     * @param  array{table?: ?string, quantities?: array<int|string, int>, add?: list<array<string, mixed>>}  $changes
     *
     * @throws ValidationException
     */
    public function edit(Order $order, array $changes, ?User $actor = null): Order
    {
        if (! in_array($order->status, [OrderStatus::Placed, OrderStatus::Confirmed], true)) {
            throw ValidationException::withMessages([
                'order' => ['Only new or accepted orders can be changed.'],
            ]);
        }
        $removed = [];

        [$order, $tableChanged, $itemsChanged] = DB::transaction(function () use ($order, $changes, $actor, &$removed): array {
            /** @var Order $order */
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items', 'business')->firstOrFail();
            $business = $order->business;
            $tableChanged = false;
            $itemsChanged = false;

            if (array_key_exists('table', $changes)) {
                $uuid = $changes['table'];
                if ($uuid === null || $uuid === '') {
                    $order->table_id = null;
                } else {
                    if ($order->service_type === ServiceType::Delivery) {
                        throw ValidationException::withMessages(['table' => ['A delivery order has no table.']]);
                    }
                    $table = $business->tables()->where('uuid', $uuid)->where('status', 'active')->first();
                    if ($table === null) {
                        throw ValidationException::withMessages(['table' => ['That table is not available.']]);
                    }
                    $order->table_id = $table->id;
                    $order->service_type = ServiceType::DineIn;
                }
                $tableChanged = $order->isDirty('table_id');
            }

            $needs = [];
            $giveBack = [];
            foreach ($changes['quantities'] ?? [] as $itemId => $qty) {
                $line = $order->items->firstWhere('id', (int) $itemId);
                if ($line === null) {
                    throw ValidationException::withMessages(['items' => ['An item in this change is not on the order.']]);
                }
                $qty = max(0, (int) $qty);
                $delta = $qty - (int) $line->quantity;
                if ($delta === 0) {
                    continue;
                }
                // Taking food off an order the kitchen already has: only with
                // the owner's leave, and the owner hears about it.
                if ($delta < 0 && $order->status === OrderStatus::Confirmed) {
                    if ($actor !== null && ! $actor->canAtShop('cancel_orders')) {
                        throw ValidationException::withMessages(['items' => ['Only staff allowed to cancel can remove items once the kitchen has the order.']]);
                    }
                    $removed[] = -$delta.' × '.$line->name;
                }
                foreach ($this->unitsOf($line) as $productId => $units) {
                    if ($delta > 0) {
                        $needs[$productId] = ($needs[$productId] ?? 0) + $units * $delta;
                    } else {
                        $giveBack[$productId] = ($giveBack[$productId] ?? 0) + $units * -$delta;
                    }
                }
                $qty === 0 ? $line->delete() : $line->update(['quantity' => $qty]);
                $itemsChanged = true;
            }

            if (! empty($changes['add'])) {
                $priced = $this->priceItems($business, $changes['add']);
                foreach ($priced['lines'] as $line) {
                    $order->items()->create($line);
                }
                foreach ($priced['stockNeeds'] as $productId => $units) {
                    $needs[$productId] = ($needs[$productId] ?? 0) + $units;
                }
                $itemsChanged = true;
            }

            $held = $order->stock_deducted ?? [];
            foreach ($this->stock->deduct($needs) as $productId => $units) {
                $held[$productId] = ($held[$productId] ?? 0) + $units;
            }
            foreach ($this->stock->giveBack($giveBack, $held) as $productId => $units) {
                $held[$productId] = ($held[$productId] ?? 0) - $units;
            }
            $held = array_filter($held, fn ($units) => $units > 0);
            $order->stock_deducted = $held === [] ? null : $held;

            // A discount at the counter: set, changed, or taken off (value 0).
            $discountChanged = false;
            if (array_key_exists('discount', $changes) && $actor !== null) {
                $order->manual_discount_percent = null;
                $order->manual_discount = 0;
                $order->discount_reason = null;
                $order->discount_by = null;
                $wanted = $changes['discount'];
                if (is_array($wanted) && (float) ($wanted['value'] ?? 0) > 0) {
                    $order->setRelation('items', $order->items()->get());
                    $base = round((float) $order->items->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity)
                        - (float) $order->bill_discount - (float) $order->coupon_discount, 2);
                    $manual = $this->manualDiscount($actor, $base, $wanted);
                    $order->manual_discount = $manual['amount'];
                    $order->manual_discount_percent = $manual['percent'];
                    $order->discount_reason = $manual['reason'];
                    $order->discount_by = $actor->id;
                }
                $discountChanged = true;
            }

            if ($itemsChanged || $discountChanged) {
                $this->reprice($order);
            }
            $order->save();

            if ($discountChanged && (float) $order->manual_discount > 0 && $actor !== null) {
                $this->discountAlert($order, $actor);
            }

            return [$order, $tableChanged, $itemsChanged];
        });

        $order->load('diningTable', 'customer', 'business');
        if ($removed !== [] && $actor !== null && $actor->isStaff()) {
            $this->ownerAlerts->raise(
                $order->business,
                'item_removed',
                "Item removed after KOT · Order {$order->token}",
                implode(', ', $removed)." removed by {$actor->name}. New total ".OwnerAlertService::money((float) $order->total).'.',
                'warning',
                ['orderId' => $order->uuid],
                $actor,
            );
        }
        if (($tableChanged || $itemsChanged) && $order->customer) {
            $what = collect([
                $tableChanged ? ($order->diningTable ? "now at table {$order->diningTable->label}" : 'table removed') : null,
                $itemsChanged ? 'items changed, new total Rs '.(float) $order->total : null,
            ])->filter()->join(' · ');
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Order updated',
                "{$order->business->name} updated your order {$order->token}: {$what}.",
                ['link' => '/orders'],
            );
        }

        return $order->fresh(['items', 'diningTable']);
    }

    /**
     * Products one unit of an order line uses (a combo: each of its items).
     *
     * @return array<int, int>
     */
    private function unitsOf(\App\Models\OrderItem $line): array
    {
        if ($line->product_id !== null) {
            return [$line->product_id => 1];
        }
        $units = [];
        $combo = $line->combo_id ? Combo::with('items')->find($line->combo_id) : null;
        foreach ($combo?->items ?? [] as $comboItem) {
            if ($comboItem->product_id !== null) {
                $units[$comboItem->product_id] = ($units[$comboItem->product_id] ?? 0) + max(1, (int) $comboItem->quantity);
            }
        }

        return $units;
    }

    /**
     * Work an edited order's bill out again from its lines (their prices stay
     * as they were when added). Coins spent beyond the new bill go back.
     *
     * @throws ValidationException
     */
    private function reprice(Order $order): void
    {
        $order->load('items');
        if ($order->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['An order needs at least one item. Cancel it instead.']]);
        }

        $subtotal = round((float) $order->items->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity), 2);
        $billDiscount = 0.0;
        $couponDiscount = 0.0;
        if ($order->coupon_id !== null) {
            $coupon = Coupon::withTrashed()->find($order->coupon_id);
            if ($coupon !== null && ($coupon->min_bill === null || $subtotal >= (float) $coupon->min_bill)) {
                $couponDiscount = $coupon->discountFor($subtotal);
            }
        } else {
            $billDiscount = $this->rewards->billDiscount($order->business, $subtotal);
        }
        // A percent discount follows the bill; a fixed one stays, but never above it.
        $manualBase = max(0.0, round($subtotal - $billDiscount - $couponDiscount, 2));
        $manualDiscount = $order->manual_discount_percent !== null
            ? round($manualBase * (float) $order->manual_discount_percent / 100, 2)
            : min((float) $order->manual_discount, $manualBase);
        $discounted = max(0.0, round($manualBase - $manualDiscount, 2));

        $coinValue = max(1, (int) config('loyalty.coin_value', 1));
        $coinsUsed = (int) min((int) $order->coins_used, floor($discounted / $coinValue));
        if ($coinsUsed < (int) $order->coins_used && $order->customer) {
            $this->loyalty->refund($order->customer, (int) $order->coins_used - $coinsUsed, $order->business, $order, "Coins back: order {$order->token} changed");
        }
        $coinDiscount = round($coinsUsed * $coinValue, 2);

        // Taxed as the order was first priced (a settings change mid-meal doesn't flip it).
        $bill = $this->tax->compute(
            $order->business,
            $order->items->map(fn ($i) => ['amount' => (float) $i->unit_price * (int) $i->quantity, 'rate' => $i->tax_rate !== null ? (float) $i->tax_rate : null])->all(),
            $billDiscount + $couponDiscount + $manualDiscount + $coinDiscount,
            (float) $order->delivery_fee,
            $order->tax_mode,
        );
        foreach ($order->items->values() as $i => $item) {
            $item->update(['tax_rate' => $bill['lines'][$i]['rate'], 'taxable_value' => $bill['lines'][$i]['taxable'], 'tax_amount' => $bill['lines'][$i]['tax']]);
        }
        $total = $bill['total'];
        $paidOnline = $order->isPaidOnline() ? (float) $order->online_amount : 0.0;
        if ($total < $paidOnline) {
            throw ValidationException::withMessages(['items' => [
                "Rs {$paidOnline} was paid online, so the bill can't go below that. Cancel the order to refund it instead.",
            ]]);
        }
        $fees = $this->fees->split($total, $paidOnline);

        $order->fill([
            'subtotal' => $subtotal,
            'bill_discount' => $billDiscount,
            'coupon_discount' => $couponDiscount,
            'manual_discount' => $manualDiscount,
            'coins_used' => $coinsUsed,
            'coin_discount' => $coinDiscount,
            'total' => $total,
            'coins_earned' => (int) $order->items->sum(fn ($i) => (int) $i->coins_earned * (int) $i->quantity),
        ] + TaxService::orderColumns($bill) + [
            'platform_fee' => $fees['platform'],
            'gateway_fee' => $fees['gateway'],
            'vendor_earning' => $fees['vendor'],
        ]);
    }

    /**
     * Move an accepted order through the kitchen: queued → preparing → ready
     * (straight to ready is fine). Ready tells the customer to collect it.
     *
     * @throws ValidationException
     */
    public function setKitchenStatus(Order $order, KitchenStatus $to): Order
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Paid], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only accepted orders are in the kitchen.'],
            ]);
        }

        $order->update(match ($to) {
            KitchenStatus::Queued => ['kitchen_status' => $to, 'kitchen_started_at' => null, 'ready_at' => null],
            KitchenStatus::Preparing => ['kitchen_status' => $to, 'kitchen_started_at' => $order->kitchen_started_at ?? Carbon::now(), 'ready_at' => null],
            KitchenStatus::Ready => ['kitchen_status' => $to, 'ready_at' => Carbon::now()],
        });

        if ($to === KitchenStatus::Ready && $order->customer) {
            $where = $order->service_type === ServiceType::Delivery ? 'It is on its way soon.' : 'Please collect it at the counter.';
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Your order is ready 🍽️',
                "Order {$order->token} at {$order->business->name} is ready. {$where}",
                ['link' => '/orders'],
            );
            $this->whatsapp->order('order_ready', $order);
        }

        return $order->fresh('items');
    }

    /**
     * What a cart costs before it is placed: the same pricing place() uses, so
     * the cart shows exactly what the order will charge. Coins and the payment
     * split are left to the cart (they depend on toggles it owns).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     *
     * @throws ValidationException for an item that can't be sold (not for a bad coupon)
     */
    public function quote(Business $business, ?User $customer, array $items, ?string $couponCode, ?ServiceType $serviceType): array
    {
        $priced = $this->priceItems($business, $items);
        $subtotal = $priced['subtotal'];

        $couponError = null;
        try {
            $discounts = $this->discounts($business, $customer, $subtotal, $couponCode);
        } catch (ValidationException $e) {
            $couponError = collect($e->errors())->flatten()->first();
            $discounts = $this->discounts($business, $customer, $subtotal, null);
        }

        $deliveryFee = $serviceType === ServiceType::Delivery ? $business->deliveryFee() : 0.0;
        $savings = round($discounts['billDiscount'] + $discounts['couponDiscount'], 2);
        $perks = $this->rewards->perks($business, $customer);
        $quoteBill = $this->tax->compute($business, self::taxLines($priced['lines']), $savings, $deliveryFee, $this->tax->modeFor($business));

        return [
            // What this order earns for next time: the come-back coupon and a stamp.
            'next' => ['comeback' => $perks['comeback'], 'loyalty' => $perks['loyalty']],
            'subtotal' => $subtotal,
            'billDiscount' => $discounts['billDiscount'],
            // The bill discount this cart would get without a coupon (a coupon replaces it).
            'billDiscountAvailable' => $this->rewards->billDiscount($business, $subtotal),
            'billNudge' => $this->rewards->billNudge($business, $subtotal),
            'coupon' => $discounts['coupon'] ? $this->rewards->couponPayload($discounts['coupon']) : null,
            'couponDiscount' => $discounts['couponDiscount'],
            'couponError' => $couponError,
            'savings' => $savings,
            'deliveryFee' => $deliveryFee,
            'comboCoinCap' => $priced['comboCoinCap'],
            // Closed or paused: the cart says so instead of letting checkout fail.
            'acceptingOrders' => $business->acceptsOrders(),
            // Before coins; the cart takes any coins off this. With GST on, the
            // tax lines below are part of it (and coins lower them a little).
            'total' => $quoteBill['total'],
            'tax' => $quoteBill['mode'] !== null ? [
                'mode' => $quoteBill['mode'],
                'cgst' => $quoteBill['cgst'],
                'sgst' => $quoteBill['sgst'],
                'serviceCharge' => $quoteBill['serviceCharge'],
                'serviceChargePercent' => $quoteBill['serviceChargePercent'],
                'roundOff' => $quoteBill['roundOff'],
            ] : null,
            'coupons' => $this->rewards->couponsFor($business, $customer)
                ->map(fn ($c) => $this->rewards->couponPayload($c) + [
                    'minBillMet' => $c->min_bill === null || $subtotal >= (float) $c->min_bill,
                    // A coupon replaces the bill discount (one offer per order).
                    'saves' => $c->min_bill === null || $subtotal >= (float) $c->min_bill
                        ? $c->discountFor($subtotal)
                        : 0.0,
                ])->all(),
        ];
    }

    /**
     * @return array{billDiscount: float, coupon: ?Coupon, couponDiscount: float}
     *
     * @throws ValidationException when the coupon can't be used
     */
    private function discounts(Business $business, ?User $customer, float $subtotal, ?string $couponCode): array
    {
        $billDiscount = $this->rewards->billDiscount($business, $subtotal);
        $coupon = null;
        $couponDiscount = 0.0;

        // One offer per order: a coupon replaces the shop's bill discount, so it
        // is only worth applying when it saves more.
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->rewards->couponForCheckout($business, $customer, $couponCode, $subtotal);
            $couponDiscount = $coupon->discountFor($subtotal);
            if ($billDiscount > 0 && $couponDiscount <= $billDiscount) {
                throw ValidationException::withMessages(['couponCode' => [
                    "Your ₹{$billDiscount} bill discount already saves more. Only one offer or coupon works per order.",
                ]]);
            }
            $billDiscount = 0.0;
        }

        return ['billDiscount' => $billDiscount, 'coupon' => $coupon, 'couponDiscount' => $couponDiscount];
    }

    /**
     * Price each cart line from the database (never the client's prices) and
     * gather what the order affects: coins, the coin cap, and stock.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{lines: list<array<string, mixed>>, subtotal: float, coinsEarned: int, comboCoinCap: int, stockNeeds: array<int, int>}
     *
     * @throws ValidationException
     */
    private function priceItems(Business $business, array $items): array
    {
        $lines = [];
        $subtotal = 0.0;
        // Units each product sells in this order, alone or inside combos.
        $stockNeeds = [];
        $coinsEarned = 0;
        // Coins are a combo perk: the most a customer can spend is the sum of
        // each combo's owner-set "accept up to N coins", times its quantity.
        $comboCoinCap = 0;

        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (($item['type'] ?? null) === 'combo') {
                $combo = $business->combos()->where('uuid', $item['id'])->first();
                if ($combo === null || ! $combo->isActiveNow()) {
                    throw ValidationException::withMessages(['items' => ['A combo in your cart is no longer available.']]);
                }
                foreach ($combo->items as $comboItem) {
                    if ($comboItem->product_id !== null) {
                        $stockNeeds[$comboItem->product_id] = ($stockNeeds[$comboItem->product_id] ?? 0)
                            + max(1, (int) $comboItem->quantity) * $quantity;
                    }
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
                    'tax_rate' => $combo->tax_rate !== null ? (float) $combo->tax_rate : null,
                    'quantity' => $quantity,
                    'coins_earned' => $earn,
                ];
            } else {
                $product = $business->products()->where('uuid', $item['id'])->with('promotions')->first();
                if ($product === null || ! $product->is_visible || ! $product->in_stock) {
                    throw ValidationException::withMessages(['items' => ['A product in your cart is no longer available.']]);
                }
                $stockNeeds[$product->id] = ($stockNeeds[$product->id] ?? 0) + $quantity;
                $variantId = $item['variant'] ?? null;
                $addonIds = array_values($item['addons'] ?? []);
                try {
                    $unit = $product->priceFor($variantId, $addonIds);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['items' => ["{$product->name}: {$e->getMessage()}"]]);
                }
                // Snapshot the picks by name, as the line's name and price are.
                $variant = collect($product->variants())->firstWhere('id', $variantId);
                $variantLabel = $variant ? Product::variantLabel($variant) : null;
                $addons = collect($product->addons())->whereIn('id', $addonIds)->pluck('name')->values()->all();
                $lines[] = [
                    'product_id' => $product->id,
                    'item_type' => 'product',
                    'name' => $variantLabel ? "{$product->name} ({$variantLabel})" : $product->name,
                    'options' => $variant || $addons !== []
                        ? ['variant' => $variantLabel, 'addons' => $addons]
                        : null,
                    'unit_price' => $unit,
                    'tax_rate' => $product->tax_rate !== null ? (float) $product->tax_rate : null,
                    'quantity' => $quantity,
                    'coins_earned' => 0,
                ];
            }

            $subtotal += $unit * $quantity;
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'coinsEarned' => $coinsEarned,
            'comboCoinCap' => $comboCoinCap,
            'stockNeeds' => $stockNeeds,
        ];
    }

    /**
     * Validate and normalise the service context against what the business offers.
     * Returns `[ServiceType, ?BusinessTable, ?string address]`.
     *
     * @return array{0: ServiceType, 1: ?BusinessTable, 2: ?string}
     *
     * @throws ValidationException
     */
    private function resolveService(Business $business, ?ServiceType $serviceType, ?string $tableUuid, ?string $serviceAddress): array
    {
        // Default to the business's preferred mode when the client sends none.
        $serviceType ??= $business->serviceModes()[0] ?? ServiceType::default();

        if (! $business->offersService($serviceType)) {
            throw ValidationException::withMessages([
                'serviceType' => ["This shop doesn't offer {$serviceType->label()}."],
            ]);
        }

        $table = null;
        if ($tableUuid !== null && $tableUuid !== '') {
            if ($serviceType !== ServiceType::DineIn) {
                throw ValidationException::withMessages(['table' => ['A table can only be set for dine-in orders.']]);
            }
            $table = $business->tables()->where('uuid', $tableUuid)->where('status', 'active')->first();
            if ($table === null) {
                throw ValidationException::withMessages(['table' => ['That table is not available.']]);
            }
        }

        $serviceAddress = $serviceType === ServiceType::Delivery ? trim((string) $serviceAddress) : null;
        if ($serviceType === ServiceType::Delivery && $serviceAddress === '') {
            throw ValidationException::withMessages(['serviceAddress' => ['A delivery address is required.']]);
        }

        return [$serviceType, $table, $serviceAddress ?: null];
    }

    /**
     * The contact number and map pin for a home delivery. The number falls back
     * to the one on the customer's account; a pin is optional (a typed address
     * is enough) but must come as a pair.
     *
     * @param  array{phone?: ?string, lat?: float|string|null, lng?: float|string|null}  $delivery
     * @return array{phone: string, lat: ?float, lng: ?float}
     *
     * @throws ValidationException
     */
    private function resolveDelivery(User $customer, array $delivery): array
    {
        $phone = preg_replace('/\D/', '', (string) ($delivery['phone'] ?? ''));
        if ($phone === '') {
            $phone = preg_replace('/\D/', '', (string) $customer->phone);
        }
        // Keep the last ten digits so "+91 98765 43210" and "09876543210" both fit.
        $phone = strlen($phone) > 10 ? substr($phone, -10) : $phone;
        if (! preg_match('/^[6-9]\d{9}$/', $phone)) {
            throw ValidationException::withMessages(['deliveryPhone' => ['Enter a valid 10-digit mobile number for the delivery.']]);
        }

        $lat = $delivery['lat'] ?? null;
        $lng = $delivery['lng'] ?? null;
        $hasPin = $lat !== null && $lng !== null && $lat !== '' && $lng !== '';

        return [
            'phone' => $phone,
            'lat' => $hasPin ? round((float) $lat, 7) : null,
            'lng' => $hasPin ? round((float) $lng, 7) : null,
        ];
    }

    /**
     * Move an order to the next state. Enforces the allowed transitions.
     *
     * @throws ValidationException
     */
    public function transition(Order $order, OrderStatus $to, User $actor, ?PaymentMethod $paymentMethod = null, ?string $reason = null): Order
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

        return DB::transaction(function () use ($order, $to, $actor, $paymentMethod, $reason): Order {
            match ($to) {
                OrderStatus::Confirmed => $this->markConfirmed($order),
                OrderStatus::Paid => $this->markPaid($order, $actor, $paymentMethod),
                OrderStatus::Completed => $this->markCompleted($order),
                OrderStatus::Cancelled => $this->markCancelled($order, $actor, $reason),
                default => null,
            };

            return $order->fresh('items');
        });
    }

    /** The shop took the order. Tell the customer, who may be watching the page. */
    private function markConfirmed(Order $order): void
    {
        $order->update([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => Carbon::now(),
            // Accepted: straight onto the kitchen screen, where the shop has one.
            'kitchen_status' => $order->business->usesKitchen() ? KitchenStatus::Queued : null,
        ]);

        if ($order->customer) {
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Order accepted 👨‍🍳',
                "{$order->business->name} accepted your order {$order->token} and is preparing it.",
                ['link' => '/orders'],
            );
            $this->whatsapp->order('order_accepted', $order);
        }
    }

    private function markCompleted(Order $order): void
    {
        $order->update(['status' => OrderStatus::Completed, 'completed_at' => Carbon::now()]);

        if ($order->customer) {
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Order completed ✅',
                "Your order {$order->token} at {$order->business->name} is complete. Thank you!",
                ['link' => '/orders'],
            );
            $this->whatsapp->order('order_completed', $order);
        }
    }

    private function markPaid(Order $order, User $actor, ?PaymentMethod $paymentMethod = null): void
    {
        $order->update([
            'status' => OrderStatus::Paid,
            'paid_at' => Carbon::now(),
            'paid_by' => $actor->id,
            // Default to cash when the owner didn't specify -- the common counter
            // case. An order already paid online in full had nothing collected at
            // the counter, so it defaults to online instead.
            'payment_method' => $paymentMethod
                ?? ($order->isPaidOnline() && $order->amountDue() <= 0 ? PaymentMethod::Online : PaymentMethod::Cod),
        ]);

        // The bill's invoice number; a shop billing GST per bill pays per bill.
        $this->tax->assignInvoiceNumber($order);
        if ($order->tax_mode !== null) {
            $this->usage->record($order->business, 'billing', $order->uuid);
        }

        // Credit the coins the combos promised.
        if ($order->coins_earned > 0 && $order->customer) {
            $this->loyalty->award($order->customer, CoinSource::OrderEarn, $order->business, [
                'amount' => $order->coins_earned,
                'reference' => $order,
                'description' => "Order {$order->token} reward",
            ]);
        }

        // Mint any next-visit coupons the combos offered, and the shop's own
        // come-back / loyalty coupons.
        $this->mintCoupons($order);
        $earned = $this->rewards->issueAfterPaid($order);

        if ($order->customer) {
            $coins = $order->coins_earned > 0 ? " You earned {$order->coins_earned} coins." : '';
            if ($earned !== []) {
                $coins .= ' New reward unlocked: '.collect($earned)->map(fn ($c) => $c->label())->join(', ').' on your next order.';
            }
            $this->notifications->notify(
                $order->customer,
                NotificationType::OrderUpdate,
                'Payment received ✅',
                "Your order {$order->token} at {$order->business->name} is paid.{$coins}",
                ['link' => '/wallet'],
            );
        }
    }

    private function markCancelled(Order $order, ?User $actor = null, ?string $reason = null): void
    {
        // Refund first: if the gateway refuses, the cancel is refused with it,
        // rather than the order going away with the customer's money still held.
        if ($order->isPaidOnline()) {
            $this->payments->refundFor($order);
        }

        $wasAccepted = $order->status === OrderStatus::Confirmed;
        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
            'cancelled_by' => $actor?->id,
            'cancel_reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 160) : null,
        ]);

        if ($actor !== null && $actor->isStaff()) {
            $this->ownerAlerts->raise(
                $order->business,
                'order_cancelled',
                "Order {$order->token} cancelled by {$actor->name}",
                trim(OwnerAlertService::money((float) $order->total).($wasAccepted ? ', after it went to the kitchen' : '').'. '
                    .($order->cancel_reason ? "Reason: {$order->cancel_reason}" : 'No reason given.')),
                $wasAccepted ? 'danger' : 'warning',
                ['orderId' => $order->uuid],
                $actor,
            );
        }
        $this->stock->restore($order);
        $this->rewards->release($order);

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
            $this->whatsapp->order('order_cancelled', $order);
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

    /**
     * A discount given at the counter: a percent or a fixed amount off what is
     * left after the shop's own offers. Staff may give it only with the
     * "discount" permission and up to their limit; the owner has no limit.
     *
     * @param  array{type?: string, value?: float|int|string, reason?: ?string}|null  $wanted
     * @return array{amount: float, percent: ?float, reason: ?string}
     *
     * @throws ValidationException
     */
    private function manualDiscount(User $actor, float $base, ?array $wanted): array
    {
        $value = (float) ($wanted['value'] ?? 0);
        if ($wanted === null || $value <= 0 || $base <= 0) {
            return ['amount' => 0.0, 'percent' => null, 'reason' => null];
        }
        if (! $actor->canAtShop('discount')) {
            throw ValidationException::withMessages(['discount' => ['You are not allowed to give discounts. Please ask the owner.']]);
        }

        $isPercent = ($wanted['type'] ?? 'percent') === 'percent';
        $percent = $isPercent ? min(100.0, $value) : null;
        $amount = round($isPercent ? $base * $percent / 100 : min($value, $base), 2);
        $effective = $base > 0 ? $amount / $base * 100 : 0.0;

        $limit = $actor->isStaff() ? $actor->staffProfile?->max_discount_percent : null;
        if ($limit !== null && $effective > (float) $limit + 0.001) {
            throw ValidationException::withMessages(['discount' => ['Your discount limit is '.rtrim(rtrim(number_format((float) $limit, 2), '0'), '.').'% of the bill.']]);
        }
        $reason = trim((string) ($wanted['reason'] ?? ''));

        return ['amount' => $amount, 'percent' => $percent, 'reason' => $reason !== '' ? mb_substr($reason, 0, 120) : null];
    }

    /** Tell the owner about a staff discount at or above their alert limit. */
    private function discountAlert(Order $order, User $actor): void
    {
        if (! $actor->isStaff()) {
            return;
        }
        $base = (float) $order->subtotal - (float) $order->bill_discount - (float) $order->coupon_discount;
        $percent = $base > 0 ? round((float) $order->manual_discount / $base * 100, 1) : 0.0;
        $threshold = (float) ($order->business->posSettings()->alerts()['discountPercent'] ?? 10);
        if ($percent + 0.001 < $threshold) {
            return;
        }
        $this->ownerAlerts->raise(
            $order->business,
            'discount',
            "{$percent}% discount by {$actor->name} · Order {$order->token}",
            OwnerAlertService::money((float) $order->manual_discount).' off a '.OwnerAlertService::money($base).' bill. '
                .($order->discount_reason ? "Reason: {$order->discount_reason}" : 'No reason given.'),
            'warning',
            ['orderId' => $order->uuid],
            $actor,
            "discount:{$order->uuid}:".(int) round((float) $order->manual_discount * 100),
        );
    }

    /**
     * Priced lines as TaxService reads them.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{amount: float, rate: float|null}>
     */
    private static function taxLines(array $lines): array
    {
        return array_map(fn ($l) => [
            'amount' => (float) $l['unit_price'] * (int) $l['quantity'],
            'rate' => $l['tax_rate'] ?? null,
        ], $lines);
    }

    /**
     * Priced lines with the GST each one carries, ready to save.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $bill  TaxService::compute()
     * @return list<array<string, mixed>>
     */
    private static function withTax(array $lines, array $bill): array
    {
        foreach ($lines as $i => $line) {
            $lines[$i]['tax_rate'] = $bill['lines'][$i]['rate'] ?? null;
            $lines[$i]['taxable_value'] = $bill['lines'][$i]['taxable'] ?? null;
            $lines[$i]['tax_amount'] = $bill['lines'][$i]['tax'] ?? null;
        }

        return $lines;
    }

    /** A short numeric token unique among the business's active orders. */
    private function uniqueToken(Business $business): string
    {
        do {
            $token = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            // AwaitingPayment counts too: it holds its token, and once paid it
            // becomes Placed alongside whatever took the same number meanwhile.
            $exists = $business->orders()
                ->whereIn('status', array_map(
                    fn ($s) => $s->value,
                    [...OrderStatus::active(), OrderStatus::AwaitingPayment],
                ))
                ->where('token', $token)
                ->exists();
        } while ($exists);

        return $token;
    }
}
