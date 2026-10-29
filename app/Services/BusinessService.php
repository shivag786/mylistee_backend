<?php

namespace App\Services;

use App\Enums\BusinessStatus;
use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\BusinessGallery;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business owner domain logic (document/phase/07). Keeps controllers thin:
 * registration (+ auto QR), profile updates with image handling, gallery
 * management, and the dashboard aggregate.
 */
class BusinessService
{
    public function __construct(
        private readonly ImageStorageService $images,
        private readonly QrService $qr,
    ) {}

    /**
     * Register a business for an owner and mint its permanent QR.
     *
     * @param  array<string, mixed>  $data
     * @param  array{logo?: UploadedFile, cover?: UploadedFile}  $files
     */
    public function register(User $owner, array $data, array $files = [], bool $draft = false): Business
    {
        return DB::transaction(function () use ($owner, $data, $files, $draft): Business {
            $data['owner_id'] = $owner->id;
            $data['created_by'] = $owner->id;
            $data['owner_name'] = $data['owner_name'] ?? $owner->name;
            // A draft is hidden (pending) until completeSetup(); anything else
            // is live straight away, as before.
            $data['status'] = $draft ? BusinessStatus::Pending : BusinessStatus::Active;
            $data['setup_completed_at'] = $draft ? null : now();

            if (isset($files['logo'])) {
                $data['logo_path'] = $this->images->store($files['logo'], 'businesses/logos');
            }
            if (isset($files['cover'])) {
                $data['cover_path'] = $this->images->store($files['cover'], 'businesses/covers');
            }

            $business = Business::create($data);
            $this->qr->createForBusiness($business);

            // refresh() pulls DB-default columns (status, counters) into memory
            // before the resource serializes them.
            return $business->refresh()->load(['category', 'gallery', 'qrCode']);
        });
    }

    /**
     * Take a draft shop live once it has what customers need to find it. A
     * shop the admin has since suspended or rejected keeps that status.
     *
     * @throws ValidationException when the address or category is missing
     */
    public function completeSetup(Business $business): Business
    {
        if ($business->setup_completed_at !== null) {
            return $business;
        }

        $missing = $business->setupMissing();
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'setup' => ['Add your shop\'s '.implode(', ', $missing).' to go live.'],
            ]);
        }

        $business->forceFill([
            'setup_completed_at' => now(),
            'status' => $business->status === BusinessStatus::Pending ? BusinessStatus::Active : $business->status,
        ])->save();

        return $business;
    }

    /**
     * Update an existing business profile, replacing images when provided.
     *
     * @param  array<string, mixed>  $data
     * @param  array{logo?: UploadedFile, cover?: UploadedFile}  $files
     */
    public function update(Business $business, array $data, array $files = [], ?User $editor = null): Business
    {
        if (isset($files['logo'])) {
            $this->images->delete($business->logo_path);
            $data['logo_path'] = $this->images->store($files['logo'], 'businesses/logos');
        }
        if (isset($files['cover'])) {
            $this->images->delete($business->cover_path);
            $data['cover_path'] = $this->images->store($files['cover'], 'businesses/covers');
        }
        if ($editor !== null) {
            $data['updated_by'] = $editor->id;
        }

        $business->update($data);

        return $business->fresh(['category', 'gallery', 'qrCode']);
    }

    /** Add a gallery image (appended to the end of the current order). */
    public function addGalleryImage(Business $business, UploadedFile $file): BusinessGallery
    {
        $path = $this->images->store($file, "businesses/{$business->id}/gallery");
        $nextOrder = (int) $business->gallery()->max('sort_order') + 1;

        return $business->gallery()->create([
            'image_path' => $path,
            'sort_order' => $nextOrder,
            'status' => 'active',
        ]);
    }

    public function removeGalleryImage(BusinessGallery $image): void
    {
        $this->images->delete($image->image_path);
        $image->delete();
    }

    /**
     * Dashboard aggregate (document/phase/07 §Home Dashboard). Metrics sourced
     * from later milestones (spins M6, redemptions M8, analytics M12) return
     * real zeros until those features land — never fabricated numbers.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Business $business): array
    {
        $today = \Illuminate\Support\Carbon::today();

        $todaySpins = $business->spins()->whereDate('created_at', $today)->count();
        $todayRewards = $business->rewards()->whereDate('won_at', $today)->count();
        $todayRedemptions = $business->rewards()
            ->where('status', 'redeemed')->whereDate('redeemed_at', $today)->count();
        $totalCustomers = $business->spins()->distinct('customer_id')->count('customer_id');
        $repeatCustomers = $business->spins()
            ->selectRaw('customer_id')->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')->get()->count();

        return [
            'business' => $business->loadMissing(['category', 'qrCode', 'gallery']),
            'metrics' => [
                // Real profile visits today (visit tracking landed in M12).
                'todayVisitors' => $business->visits()->whereDate('created_at', $today)->count(),
                'todaySpins' => $todaySpins,
                'todayRewards' => $todayRewards,
                'todayRedemptions' => $todayRedemptions,
                'totalCustomers' => $totalCustomers,
                'repeatCustomers' => $repeatCustomers,
                'totalVisits' => $business->total_visits,
                'totalFollowers' => $business->followers()->count(),
                // Every order the shop has taken, not counting unpaid online checkouts.
                'totalOrders' => $business->orders()
                    ->where('status', '!=', \App\Enums\OrderStatus::AwaitingPayment->value)->count(),
                'completedOrders' => $business->orders()
                    ->where('status', \App\Enums\OrderStatus::Completed->value)->count(),
                'totalSpins' => $business->total_spins,
                'totalRewards' => $business->total_rewards,
            ],
            'onboarding' => $this->onboardingChecklist($business),
            'plan' => $this->planSummary($business),
            'sales' => $this->salesSummary($business),
        ];
    }

    /**
     * Money in, for the vendor app's home screen: today's orders and takings,
     * what the shop keeps after Listee's fee, and a 7-day trend. "Sales" are
     * orders that were paid; an order counts on the day it was paid.
     *
     * @return array<string, mixed>
     */
    private function salesSummary(Business $business): array
    {
        $today = \Illuminate\Support\Carbon::today();
        $from = $today->copy()->subDays(6);

        $paid = $business->orders()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Completed->value])
            ->where('paid_at', '>=', $from)
            ->get(['total', 'vendor_earning', 'platform_fee', 'paid_at']);

        $byDay = [];
        foreach ($paid as $order) {
            $key = $order->paid_at->toDateString();
            $byDay[$key] = ($byDay[$key] ?? 0) + (float) $order->total;
        }

        $series = [];
        for ($day = $from->copy(); $day->lte($today); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = ['date' => $key, 'sales' => round($byDay[$key] ?? 0, 2)];
        }

        $todayPaid = $paid->filter(fn ($o) => $o->paid_at->isSameDay($today));

        return [
            'todayOrders' => $business->orders()
                ->whereNotIn('status', [OrderStatus::AwaitingPayment->value, OrderStatus::Cancelled->value])
                ->whereDate('created_at', $today)
                ->count(),
            'todaySales' => round((float) $todayPaid->sum('total'), 2),
            // Older orders have no stored earning: the total less Listee's fee.
            'todayEarnings' => round((float) $todayPaid->sum(
                fn ($o) => $o->vendor_earning ?? ((float) $o->total - (float) $o->platform_fee),
            ), 2),
            'waitingOrders' => $business->orders()->where('status', OrderStatus::Placed->value)->count(),
            'weekSales' => round((float) $paid->sum('total'), 2),
            'series' => $series,
        ];
    }

    /**
     * Compact plan summary for the dashboard (Milestone 13). Reads the business's
     * current plan (active subscription, else the default free plan).
     *
     * @return array{key: string, name: string, maxActiveOffers: int|null}
     */
    private function planSummary(Business $business): array
    {
        $plan = $business->currentPlan();

        return [
            'key' => $plan?->key ?? 'free',
            'name' => $plan?->name ?? 'Free',
            'maxActiveOffers' => $plan?->max_active_offers,
        ];
    }

    /**
     * First-login onboarding checklist (document/phase/07 §First Login Experience).
     *
     * @return list<array{key: string, label: string, done: bool}>
     */
    private function onboardingChecklist(Business $business): array
    {
        return [
            ['key' => 'profile', 'label' => 'Complete business profile', 'done' => true],
            ['key' => 'logo', 'label' => 'Upload logo', 'done' => (bool) $business->logo_path],
            ['key' => 'cover', 'label' => 'Upload cover image', 'done' => (bool) $business->cover_path],
            ['key' => 'gallery', 'label' => 'Add gallery photos', 'done' => $business->gallery()->exists()],
            ['key' => 'offer', 'label' => 'Create your first offer', 'done' => $business->offers()->exists()],
            ['key' => 'qr', 'label' => 'Download your QR code', 'done' => (bool) $business->qrCode?->download_count],
        ];
    }
}
