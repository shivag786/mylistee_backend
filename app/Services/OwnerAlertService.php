<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Business;
use App\Models\OwnerAlert;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tells the owner what they would want to know while away from the counter:
 * a staff member cancelled a bill or took an item off it after the kitchen got
 * it, gave a big discount, the cash count came out short, a customer's order
 * is waiting, stock ran out, someone hasn't checked in, and the day's sales.
 *
 * Each alert is kept (the Alerts screen) and pushed to the owner's phones —
 * and to staff the owner gave "Get owner alerts". The owner turns each kind
 * on or off and sets the limits (BusinessPosSetting::ALERT_DEFAULTS).
 */
class OwnerAlertService
{
    /** Alert type => the owner's on/off setting for it. */
    private const SETTING = [
        'order_cancelled' => 'cancel',
        'item_removed' => 'itemRemoved',
        'discount' => 'discount',
        'cash_difference' => 'cashDifference',
        'order_waiting' => 'orderWaiting',
        'low_stock' => 'lowStock',
        'daily_summary' => 'dailySummary',
        'attendance' => 'attendance',
        'staff_login' => 'staffLogin',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Raise an alert, unless the owner switched that kind off or one with the
     * same [$dedupeKey] was already raised for the shop.
     *
     * @param  array<string, mixed>  $data  e.g. ['orderId' => uuid]
     */
    public function raise(
        Business $business,
        string $type,
        string $title,
        ?string $body = null,
        string $severity = 'warning',
        array $data = [],
        ?User $actor = null,
        ?string $dedupeKey = null,
    ): ?OwnerAlert {
        $setting = self::SETTING[$type] ?? null;
        if ($setting !== null && ! ($business->posSettings()->alerts()[$setting] ?? true)) {
            return null;
        }
        if ($dedupeKey !== null && OwnerAlert::where('business_id', $business->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            $alert = OwnerAlert::create([
                'business_id' => $business->id,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'data' => $data === [] ? null : $data,
                'actor_id' => $actor?->id,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (QueryException) {
            return null; // raced with the same dedupe key
        }

        DB::afterCommit(fn () => $this->push($business, $alert));

        return $alert;
    }

    /** Who hears it: the owner, and staff allowed to get owner alerts. @return list<User> */
    public function recipients(Business $business): array
    {
        $users = [];
        if ($business->owner) {
            $users[] = $business->owner;
        }
        foreach ($business->staff()->where('active', true)->with('user')->get() as $staff) {
            if ($staff->allows('alerts') && $staff->user) {
                $users[] = $staff->user;
            }
        }

        return $users;
    }

    /** "₹1,250" the way the app shows money. */
    public static function money(float $amount): string
    {
        $rounded = round($amount, 2);

        return '₹'.(fmod($rounded, 1.0) === 0.0 ? number_format($rounded) : number_format($rounded, 2));
    }

    private function push(Business $business, OwnerAlert $alert): void
    {
        foreach ($this->recipients($business) as $user) {
            try {
                $this->notifications->notify(
                    $user,
                    NotificationType::OwnerAlert,
                    $alert->title,
                    $alert->body,
                    array_filter([
                        'link' => '/business/alerts',
                        'tag' => 'alert-'.$alert->uuid,
                        'alertType' => $alert->type,
                        'urgent' => $alert->severity === 'danger' ? '1' : null,
                        'orderId' => isset($alert->data['orderId']) ? (string) $alert->data['orderId'] : null,
                    ]),
                );
            } catch (\Throwable $e) {
                report($e); // a failed push never breaks what raised the alert
            }
        }
    }
}
