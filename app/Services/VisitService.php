<?php

namespace App\Services;

use App\Enums\CoinSource;
use App\Models\Business;
use App\Models\BusinessVisit;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records visits to a business profile (document/phase/02 §Customer Visit).
 * A visit is logged when the public profile is opened; rapid reloads by the
 * same customer/IP are de-duplicated within a short window so counts stay
 * meaningful. Keeps the denormalized `businesses.total_visits` counter in step.
 */
class VisitService
{
    /** De-dupe window: repeat opens by the same visitor inside this don't re-count. */
    private const DEDUPE_MINUTES = 30;

    public function __construct(private readonly LoyaltyService $loyalty) {}

    /**
     * Record a profile visit. Best-effort: never let logging break the page load.
     */
    public function record(Business $business, ?User $customer, Request $request): ?BusinessVisit
    {
        $ip = $request->ip();

        $recent = $business->visits()
            ->where('created_at', '>=', now()->subMinutes(self::DEDUPE_MINUTES))
            ->where(function ($q) use ($customer, $ip): void {
                if ($customer !== null) {
                    $q->where('customer_id', $customer->id);
                } else {
                    $q->whereNull('customer_id')->where('ip_address', $ip);
                }
            })
            ->exists();

        if ($recent) {
            return null;
        }

        $visit = $business->visits()->create([
            'customer_id' => $customer?->id,
            'ip_address' => $ip,
            'device' => substr((string) $request->userAgent(), 0, 255),
            'referrer' => substr((string) $request->headers->get('referer'), 0, 255) ?: null,
            'source' => $request->query('src') ? substr((string) $request->query('src'), 0, 32) : 'qr',
        ]);

        $business->increment('total_visits');

        // Reward a signed-in customer: a one-time bonus the first time they open
        // this shop, otherwise a once-a-day check-in for coming back.
        if ($customer !== null) {
            $firstScan = $this->loyalty->awardOnce($customer, CoinSource::FirstScan, $business, [
                'reference' => $visit,
            ]);

            if ($firstScan === null) {
                $this->loyalty->awardOncePerDay($customer, CoinSource::Checkin, $business, [
                    'reference' => $visit,
                ]);
            }
        }

        return $visit;
    }
}
