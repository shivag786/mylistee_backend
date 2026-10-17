<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PersonalLinkGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Personal link — store mode sold on its own, by the day.
 *
 * A shop whose personal link is live keeps customers who arrive by its QR or
 * link inside that shop (see Business::hasStoreMode()). The owner switches it
 * on and gets a free trial, once; after that they buy day packs with Razorpay.
 * Packs stack: buying while days remain adds on top of them.
 *
 * Payment follows SubscriptionPaymentService (checkout → browser pays → verify,
 * with the webhook as the safety net), on payments rows with purpose
 * `personal_link` so neither flow ever handles the other's money.
 */
class PersonalLinkService
{
    public const PURPOSE = 'personal_link';

    /** Pack key => days. Prices are admin settings (personalLinkPrice<days>). */
    public const PACKS = ['month' => 30, 'quarter' => 90, 'year' => 365];

    /** What the same days cost at the daily rate — shown as the "was" price. */
    public const DAILY_RATE = 5;

    public function __construct(
        private readonly RazorpayService $razorpay,
        private readonly SettingService $settings,
    ) {}

    /**
     * Everything the owner screen shows.
     *
     * @return array<string, mixed>
     */
    public function state(Business $business): array
    {
        $expires = $business->personal_link_expires_at;
        $active = $business->hasActivePersonalLink();

        return [
            'enabled' => (bool) $business->personal_link_enabled,
            'active' => $active,
            'expiresAt' => $expires?->toIso8601String(),
            // Whole days left, rounded up: 1h left still reads "1 day".
            'daysLeft' => $expires !== null && $expires->isFuture()
                ? (int) ceil(now()->diffInSeconds($expires) / 86400)
                : 0,
            'trialAvailable' => $business->personal_link_trial_used_at === null,
            'trialDays' => $this->trialDays(),
            'onTrial' => $active && ! PersonalLinkGrant::where('business_id', $business->id)
                ->where('status', 'active')->where('source', '!=', 'trial')->exists(),
            'packs' => $this->packs(),
            'paymentsLive' => $this->razorpay->isConfigured(),
            // Newest first: what was added, when, and the expiry before/after.
            'history' => PersonalLinkGrant::where('business_id', $business->id)
                ->latest('id')->limit(20)->get()->map->toApi()->all(),
        ];
    }

    /** @return list<array{key: string, days: int, price: float, regularPrice: float, perDay: float}> */
    public function packs(): array
    {
        $out = [];
        foreach (self::PACKS as $key => $days) {
            $price = (float) $this->settings->get("personalLinkPrice{$days}");
            $out[] = [
                'key' => $key,
                'days' => $days,
                'price' => $price,
                'regularPrice' => (float) ($days * self::DAILY_RATE),
                'perDay' => round($price / $days, 2),
            ];
        }

        return $out;
    }

    /**
     * Owner's switch. Turning it on for the first time starts the free trial.
     * Turning it off only hides store mode — paid days keep counting down.
     */
    public function setEnabled(Business $business, bool $enabled): Business
    {
        return DB::transaction(function () use ($business, $enabled): Business {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            $business->forceFill(['personal_link_enabled' => $enabled])->save();

            if ($enabled && $business->personal_link_trial_used_at === null && $this->trialDays() > 0) {
                $this->applyDays($business, new PersonalLinkGrant([
                    'source' => 'trial',
                    'days' => $this->trialDays(),
                    'amount' => 0,
                ]));
            }

            $business->forgetPlanCache();

            return $business;
        });
    }

    /**
     * Open a Razorpay order for a pack.
     *
     * @return array<string, mixed>
     */
    public function checkout(Business $business, string $packKey, User $actor): array
    {
        $pack = collect($this->packs())->firstWhere('key', $packKey);
        if ($pack === null || $pack['price'] <= 0) {
            throw ValidationException::withMessages(['pack' => ['Choose a valid pack.']]);
        }

        if (! $this->razorpay->isConfigured()) {
            throw new RuntimeException('Online payments are not available right now. Please try again later.');
        }

        $amountPaise = $this->razorpay->toPaise($pack['price']);
        $receipt = Str::limit("plink_{$pack['days']}_{$business->id}_".now()->format('YmdHis'), 40, '');

        $order = $this->razorpay->createOrder($amountPaise, 'INR', $receipt, [
            'businessId' => (string) $business->uuid,
            'businessName' => Str::limit($business->name, 60, ''),
            'purpose' => self::PURPOSE,
            'days' => (string) $pack['days'],
        ]);

        $payment = Payment::create([
            'business_id' => $business->id,
            'purpose' => self::PURPOSE,
            'created_by' => $actor->id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $order['id'],
            'receipt' => $receipt,
            'status' => PaymentStatus::Created,
            'amount' => $this->razorpay->toRupees($amountPaise),
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'meta' => ['order' => $order, 'pack' => $pack['key'], 'days' => $pack['days']],
        ]);

        return [
            'paymentId' => $payment->uuid,
            'keyId' => $this->razorpay->publicKey(),
            'orderId' => $order['id'],
            'amount' => $amountPaise,
            'amountDisplay' => (float) $payment->amount,
            'currency' => 'INR',
            'name' => config('razorpay.checkout.name'),
            'description' => "Personal link · {$pack['days']} days",
            'themeColor' => config('razorpay.checkout.theme_color'),
            'logo' => config('razorpay.checkout.logo'),
            'prefill' => [
                'name' => $business->owner_name ?: $actor->name,
                'email' => $business->email ?: $actor->email,
                'contact' => $business->phone ?: ($actor->phone ?? ''),
            ],
            'notes' => [
                'businessName' => $business->name,
                'pack' => "{$pack['days']} days",
            ],
        ];
    }

    /**
     * Verify the Checkout handshake; on success add the pack's days.
     * Idempotent — a captured payment is not counted twice.
     */
    public function verify(Business $business, string $orderId, string $paymentId, string $signature): Payment
    {
        $payment = Payment::where('business_id', $business->id)
            ->where('purpose', self::PURPOSE)
            ->where('gateway_order_id', $orderId)
            ->first();

        if ($payment === null) {
            throw ValidationException::withMessages([
                'razorpayOrderId' => ['We could not find this payment. Please try again.'],
            ]);
        }

        if ($payment->status === PaymentStatus::Captured) {
            return $payment;
        }

        if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            $this->markFailed($payment, 'SIGNATURE_MISMATCH', 'Payment signature verification failed.');
            throw ValidationException::withMessages([
                'razorpayPaymentId' => ['We could not verify this payment. If you were charged, the amount will be refunded automatically.'],
            ]);
        }

        // The signature proves the pair is authentic, not that money moved.
        $remote = $this->razorpay->fetchPayment($paymentId);

        if (($remote['order_id'] ?? null) !== $orderId || (int) ($remote['amount'] ?? 0) !== (int) $payment->amount_paise) {
            Log::error('Personal link payment did not match its order', ['paymentId' => $payment->id]);
            throw ValidationException::withMessages([
                'razorpayPaymentId' => ['This payment does not match the pack you selected.'],
            ]);
        }

        if (($remote['status'] ?? null) !== 'captured') {
            $this->markFailed(
                $payment,
                (string) ($remote['error_code'] ?? 'NOT_CAPTURED'),
                (string) ($remote['error_description'] ?? 'The payment was not completed.'),
            );
            throw ValidationException::withMessages([
                'razorpayPaymentId' => ['This payment has not been completed. Please try again.'],
            ]);
        }

        // verify() and the webhook race: re-read under a lock, add days once.
        DB::transaction(function () use ($payment, $remote, $signature): void {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked !== null && $locked->status !== PaymentStatus::Captured) {
                $this->capture($locked, $remote, $signature);
            }
        });

        return $payment->refresh();
    }

    public function recordClientFailure(Business $business, string $orderId, ?string $code, ?string $description): void
    {
        $payment = Payment::where('business_id', $business->id)
            ->where('purpose', self::PURPOSE)
            ->where('gateway_order_id', $orderId)
            ->first();

        if ($payment !== null && $payment->status !== PaymentStatus::Captured) {
            $this->markFailed($payment, $code ?: 'PAYMENT_FAILED', $description ?: 'The payment could not be completed.');
        }
    }

    /**
     * Webhook hand-off. Returns 'not_ours' for anything that is not a
     * personal-link payment, so the caller passes it on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');

        // An admin's payment link was paid (the related payment.captured event
        // has an order we never created, so this is the one that counts).
        if ($event === 'payment_link.paid') {
            $linkId = (string) ($payload['payload']['payment_link']['entity']['id'] ?? '');

            return $linkId === '' ? 'not_ours' : $this->applyLinkPaid($linkId, (array) ($payload['payload']['payment']['entity'] ?? []));
        }

        $entity = $payload['payload']['payment']['entity'] ?? null;
        $orderId = is_array($entity) ? (string) ($entity['order_id'] ?? '') : '';

        if ($orderId === '' || ! in_array($event, ['payment.captured', 'order.paid', 'payment.failed'], true)) {
            return 'not_ours';
        }

        return DB::transaction(function () use ($event, $entity, $orderId): string {
            $payment = Payment::where('gateway_order_id', $orderId)
                ->where('purpose', self::PURPOSE)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return 'not_ours';
            }
            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate';
            }

            if ($event === 'payment.failed') {
                $this->markFailed(
                    $payment,
                    (string) ($entity['error_code'] ?? 'PAYMENT_FAILED'),
                    (string) ($entity['error_description'] ?? 'The payment could not be completed.'),
                );

                return 'failed';
            }

            if ((int) ($entity['amount'] ?? 0) !== (int) $payment->amount_paise) {
                Log::error('Personal link webhook amount mismatch', ['orderId' => $orderId]);

                return 'mismatch';
            }

            $this->capture($payment, $entity);

            return 'captured';
        });
    }

    /**
     * Shops to remind today: enabled, running out within the reminder window,
     * not yet reminded today.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Business>
     */
    public function dueForReminder()
    {
        $days = max(1, (int) $this->settings->get('personalLinkReminderDays'));
        $today = now(config('app.business_timezone'))->toDateString();

        return Business::query()
            ->where('personal_link_enabled', true)
            ->where('personal_link_expires_at', '>', now())
            ->where('personal_link_expires_at', '<=', now()->addDays($days))
            ->where(fn ($q) => $q->whereNull('personal_link_reminded_on')->orWhere('personal_link_reminded_on', '<', $today));
    }

    // ------------------------------------------------------------ admin side

    /**
     * Resolve what an admin picked: a pack key (its days + current price) or
     * custom days.
     *
     * @return array{0: ?string, 1: int, 2: float}  [packKey|null, days, defaultPrice]
     */
    public function resolvePack(?string $packKey, ?int $customDays): array
    {
        if ($packKey !== null && $packKey !== 'custom') {
            $pack = collect($this->packs())->firstWhere('key', $packKey);
            if ($pack === null) {
                throw ValidationException::withMessages(['pack' => ['Choose a valid pack.']]);
            }

            return [$pack['key'], $pack['days'], $pack['price']];
        }

        if ($customDays === null || $customDays < 1 || $customDays > 3650) {
            throw ValidationException::withMessages(['days' => ['Enter between 1 and 3650 days.']]);
        }

        return [null, $customDays, (float) ($customDays * self::DAILY_RATE)];
    }

    /**
     * Admin gives days without an online payment — complimentary, or paid in
     * cash/UPI outside the app (record the amount received + a note).
     */
    public function grantFree(Business $business, ?string $packKey, ?int $customDays, float $amountReceived, ?string $note, User $admin): PersonalLinkGrant
    {
        [$pack, $days] = $this->resolvePack($packKey, $customDays);

        return DB::transaction(function () use ($business, $pack, $days, $amountReceived, $note, $admin): PersonalLinkGrant {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            return $this->applyDays($business, new PersonalLinkGrant([
                'source' => 'admin_free',
                'pack' => $pack,
                'days' => $days,
                'amount' => max(0, $amountReceived),
                'note' => $note,
                'granted_by' => $admin->id,
            ]));
        });
    }

    /**
     * Admin raises a Razorpay payment link for a package. Nothing changes for
     * the shop until it is paid; then the days are added (webhook, or a sync
     * when the owner/admin opens the screen).
     */
    public function createPaymentLink(Business $business, ?string $packKey, ?int $customDays, ?float $amount, ?string $note, bool $notify, User $admin): PersonalLinkGrant
    {
        [$pack, $days, $price] = $this->resolvePack($packKey, $customDays);
        $amount ??= $price;

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => ['A payment link needs at least ₹1. Use "Give free" instead.']]);
        }
        if (! $this->razorpay->isConfigured()) {
            throw new RuntimeException('Razorpay is not configured. Add the keys in Admin > Settings first.');
        }

        $uuid = (string) Str::uuid();
        $phone = preg_replace('/\D/', '', (string) ($business->phone ?: $business->owner?->phone));
        $email = $business->email ?: $business->owner?->email;
        $customer = array_filter([
            'name' => Str::limit($business->owner_name ?: $business->name, 60, ''),
            'contact' => strlen((string) $phone) >= 10 ? '+91'.substr((string) $phone, -10) : null,
            'email' => $email ?: null,
        ]);

        $link = $this->razorpay->createPaymentLink([
            'amount' => $this->razorpay->toPaise($amount),
            'description' => Str::limit("Personal link · {$days} days · {$business->name}", 255, ''),
            'reference_id' => Str::limit('plg_'.$uuid, 40, ''),
            'customer' => $customer,
            'notify' => ['sms' => $notify && isset($customer['contact']), 'email' => $notify && isset($customer['email'])],
            'reminder_enable' => true,
            'expire_by' => now()->addDays(15)->timestamp,
            'callback_url' => rtrim((string) config('app.frontend_url'), '/').'/business/personal-link',
            'callback_method' => 'get',
            'notes' => ['purpose' => self::PURPOSE, 'grant' => $uuid, 'businessId' => (string) $business->uuid],
        ]);

        return DB::transaction(function () use ($uuid, $link, $business, $amount, $days, $pack, $note, $admin): PersonalLinkGrant {
            $payment = Payment::create([
                'business_id' => $business->id,
                'purpose' => self::PURPOSE,
                'created_by' => $admin->id,
                'gateway' => 'razorpay',
                'gateway_order_id' => $link['id'],
                'receipt' => Str::limit('plg_'.$uuid, 40, ''),
                'status' => PaymentStatus::Created,
                'amount' => $amount,
                'amount_paise' => $this->razorpay->toPaise($amount),
                'currency' => 'INR',
                'meta' => ['paymentLink' => $link, 'pack' => $pack, 'days' => $days],
            ]);

            $grant = new PersonalLinkGrant([
                'business_id' => $business->id,
                'source' => 'admin_link',
                'status' => 'pending',
                'pack' => $pack,
                'days' => $days,
                'amount' => $amount,
                'payment_id' => $payment->id,
                'link_id' => $link['id'],
                'link_url' => $link['short_url'] ?? null,
                'note' => $note,
                'granted_by' => $admin->id,
            ]);
            $grant->uuid = $uuid;
            $grant->save();

            return $grant;
        });
    }

    /** Ask Razorpay whether a pending link was paid, and apply it if so. */
    public function syncLink(PersonalLinkGrant $grant): PersonalLinkGrant
    {
        if ($grant->status !== 'pending' || $grant->link_id === null) {
            return $grant;
        }

        $link = $this->razorpay->fetchPaymentLink($grant->link_id);
        $status = (string) ($link['status'] ?? '');

        if ($status === 'paid') {
            $paid = collect($link['payments'] ?? [])->firstWhere('status', 'captured') ?? [];
            $this->applyLinkPaid($grant->link_id, [
                'id' => $paid['payment_id'] ?? null,
                'method' => $paid['method'] ?? null,
                'amount' => (int) ($link['amount_paid'] ?? $link['amount'] ?? 0),
            ]);
        } elseif (in_array($status, ['cancelled', 'expired'], true)) {
            $this->markLinkDead($grant, strtoupper($status), "Payment link {$status}.");
        }

        return $grant->refresh();
    }

    /** Sync a shop's pending links — cheap enough for page loads (at most a few, throttled). */
    public function syncPending(Business $business): void
    {
        PersonalLinkGrant::where('business_id', $business->id)
            ->where('status', 'pending')
            ->where('updated_at', '<', now()->subSeconds(20))
            ->limit(3)
            ->get()
            ->each(function (PersonalLinkGrant $grant): void {
                try {
                    $grant->touch(); // throttles the next check
                    $this->syncLink($grant);
                } catch (\Throwable $e) {
                    Log::warning('Personal link sync failed', ['grant' => $grant->uuid, 'error' => $e->getMessage()]);
                }
            });
    }

    /** Withdraw an unpaid link. */
    public function cancelLink(PersonalLinkGrant $grant): PersonalLinkGrant
    {
        if ($grant->status !== 'pending') {
            throw ValidationException::withMessages(['grant' => ['Only an unpaid link can be cancelled.']]);
        }

        $grant = $this->syncLink($grant); // it may have just been paid
        if ($grant->status !== 'pending') {
            return $grant;
        }

        $this->razorpay->cancelPaymentLink((string) $grant->link_id);
        $this->markLinkDead($grant, 'CANCELLED', 'Payment link cancelled by admin.');

        return $grant->refresh();
    }

    private function markLinkDead(PersonalLinkGrant $grant, string $code, string $description): void
    {
        $grant->forceFill(['status' => 'cancelled'])->save();
        if ($grant->payment !== null && $grant->payment->status !== PaymentStatus::Captured) {
            $this->markFailed($grant->payment, $code, $description);
        }
    }

    /**
     * A payment link was paid: capture its payment row and add the days.
     * Safe to call twice (webhook + sync race).
     *
     * @param  array<string, mixed>  $paymentEntity
     */
    private function applyLinkPaid(string $linkId, array $paymentEntity): string
    {
        return DB::transaction(function () use ($linkId, $paymentEntity): string {
            $payment = Payment::where('gateway_order_id', $linkId)
                ->where('purpose', self::PURPOSE)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return 'not_ours';
            }
            if ($payment->status === PaymentStatus::Captured) {
                return 'duplicate';
            }
            if (isset($paymentEntity['amount']) && (int) $paymentEntity['amount'] < (int) $payment->amount_paise) {
                Log::error('Personal link payment link underpaid', ['linkId' => $linkId]);

                return 'mismatch';
            }

            $this->capture($payment, array_filter($paymentEntity, fn ($v) => $v !== null));

            return 'captured';
        });
    }

    // ---------------------------------------------------------------- internals

    /** @param  array<string, mixed>  $remote */
    private function capture(Payment $payment, array $remote, ?string $signature = null): void
    {
        $payment->forceFill([
            'gateway_payment_id' => $remote['id'] ?? $payment->gateway_payment_id,
            'gateway_signature' => $signature ?? $payment->gateway_signature,
            'status' => PaymentStatus::Captured,
            'method' => $remote['method'] ?? null,
            'paid_at' => now(),
            'meta' => array_merge($payment->meta ?? [], ['payment' => $remote]),
        ])->save();

        $business = Business::whereKey($payment->business_id)->lockForUpdate()->first();
        if ($business === null) {
            Log::critical('Captured personal link payment has no business', ['paymentId' => $payment->id]);

            return;
        }

        // A payment link raised by an admin already has its (pending) grant.
        $grant = PersonalLinkGrant::where('payment_id', $payment->id)->lockForUpdate()->first()
            ?? new PersonalLinkGrant([
                'source' => 'online',
                'pack' => $payment->meta['pack'] ?? null,
                'days' => (int) ($payment->meta['days'] ?? 0),
                'amount' => $payment->amount,
                'payment_id' => $payment->id,
            ]);

        if ($grant->exists && $grant->status === 'active') {
            return; // already applied
        }

        $this->applyDays($business, $grant);
    }

    /**
     * The one place days are added. Stacks on what is left (so buying with 10
     * days remaining gives 10 + pack), switches the link on, records the
     * expiry before/after on the grant and resets reminders.
     */
    private function applyDays(Business $business, PersonalLinkGrant $grant): PersonalLinkGrant
    {
        $before = $business->personal_link_expires_at;
        $after = $this->extend($before, $grant->days);

        $business->forceFill([
            'personal_link_enabled' => true,
            // Any package counts as having had the trial — it is never offered after.
            'personal_link_trial_used_at' => $business->personal_link_trial_used_at ?? now(),
            'personal_link_expires_at' => $after,
            'personal_link_reminded_on' => null,
        ])->save();
        $business->forgetPlanCache();

        $grant->forceFill([
            'business_id' => $business->id,
            'status' => 'active',
            'expires_before' => $before,
            'expires_after' => $after,
            'applied_at' => now(),
        ])->save();

        return $grant;
    }

    /** Add days on top of what is left, or from now once it has run out. */
    private function extend(?\DateTimeInterface $expires, int $days): Carbon
    {
        $from = $expires !== null && now()->lt($expires) ? Carbon::instance($expires) : now();

        return $from->copy()->addDays($days);
    }

    private function trialDays(): int
    {
        return max(0, (int) $this->settings->get('personalLinkTrialDays'));
    }

    private function markFailed(Payment $payment, string $code, string $description): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'error_code' => Str::limit($code, 64, ''),
            'error_description' => Str::limit($description, 255, ''),
            'failed_at' => now(),
        ])->save();
    }
}
