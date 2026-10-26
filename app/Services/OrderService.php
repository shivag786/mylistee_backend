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

            $total = round($discounted - $coinDiscount + $deliveryFee, 2);
            ['online' => $online, 'fee' => $fee] = $this->payments->split($business, $total, $paymentChoice);
            // Listee's cut comes out of the shop's share, not the customer's bill.
            $fees = $this->fees->split($total, $online);

            // Anything to collect online holds the order back until it lands.
            $awaitingPayment = $online > 0;

            // Refuses the order (with "Only N left") when a counted product is short.
            $stockTaken = $this->stock->deduct($priced['stockNeeds']);

            $order = Order::create([
                'token' => $this->uniqueToken($business),
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
            ]);

            foreach ($priced['lines'] as $line) {
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
     * shop's bill discount applies) but there is no customer account: no coins,
     * no coupons, and the name said at the counter goes in `customer_label`.
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
    ): Order {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Add at least one item.']]);
        }

        [$serviceType, $table, $serviceAddress] = $this->resolveService($business, $serviceType, $tableUuid, $serviceAddress);
        $deliveryFee = $serviceType === ServiceType::Delivery ? $business->deliveryFee() : 0.0;

        $order = DB::transaction(function () use ($business, $items, $serviceType, $table, $serviceAddress, $deliveryFee, $note, $customerLabel): Order {
            $priced = $this->priceItems($business, $items);
            $subtotal = $priced['subtotal'];
            ['billDiscount' => $billDiscount] = $this->discounts($business, null, $subtotal, null);
            $total = round($subtotal - $billDiscount + $deliveryFee, 2);
            $fees = $this->fees->split($total, 0.0);
            $stockTaken = $this->stock->deduct($priced['stockNeeds']);
            $now = Carbon::now();

            $order = Order::create([
                'token' => $this->uniqueToken($business),
                'business_id' => $business->id,
                'customer_id' => null,
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
                'coupon_discount' => 0,
                'coins_used' => 0,
                'coin_discount' => 0,
                'total' => $total,
                'delivery_fee' => $deliveryFee,
                'coins_earned' => 0,
                'stock_deducted' => $stockTaken === [] ? null : $stockTaken,
                'note' => $note,
                'service_address' => $serviceAddress,
                'placed_at' => $now,
                'confirmed_at' => $now,
            ]);

            foreach ($priced['lines'] as $line) {
                $order->items()->create($line);
            }

            return $order;
        });

        if ($paidWith !== null) {
            $order = $this->transition($order, OrderStatus::Paid, $actor, $paidWith);
        }

        return $order->load('items');
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
            // Before coins; the cart takes any coins off this.
            'total' => round($subtotal - $savings + $deliveryFee, 2),
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
    public function transition(Order $order, OrderStatus $to, User $actor, ?PaymentMethod $paymentMethod = null): Order
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

        return DB::transaction(function () use ($order, $to, $actor, $paymentMethod): Order {
            match ($to) {
                OrderStatus::Confirmed => $this->markConfirmed($order),
                OrderStatus::Paid => $this->markPaid($order, $actor, $paymentMethod),
                OrderStatus::Completed => $this->markCompleted($order),
                OrderStatus::Cancelled => $this->markCancelled($order),
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

    private function markCancelled(Order $order): void
    {
        // Refund first: if the gateway refuses, the cancel is refused with it,
        // rather than the order going away with the customer's money still held.
        if ($order->isPaidOnline()) {
            $this->payments->refundFor($order);
        }

        $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => Carbon::now()]);
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
