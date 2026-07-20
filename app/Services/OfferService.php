<?php

namespace App\Services;

use App\Enums\OfferStatus;
use App\Models\Business;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Offer domain logic (document/phase/07 §Offer Management, phase/02 §Free Plan
 * Rules). Plan limits live here so they stay configurable rather than hardcoded
 * into migrations or controllers.
 */
class OfferService
{
    /**
     * Fallback caps used only when no plan is seeded at all. The real limits
     * come from the business's current plan (Milestone 13) — a null plan limit
     * means "unlimited".
     */
    public const FREE_MAX_ACTIVE = 3;

    public const FREE_MAX_VALIDITY_DAYS = 3;

    public function __construct(private readonly ImageStorageService $images) {}

    /**
     * Create an offer for a business, enforcing the free-plan limits.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Business $business, array $data, ?UploadedFile $image = null, ?User $author = null): Offer
    {
        $this->assertWithinValidity($business, $data['starts_at'], $data['ends_at']);
        $this->assertActiveQuotaAvailable($business);

        if ($image) {
            $data['image_path'] = $this->images->store($image, "businesses/{$business->id}/offers");
        }

        $data['remaining_quantity'] = $data['total_quantity'] ?? null;
        $data['created_by'] = $author?->id;

        return $business->offers()->create($data)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Offer $offer, array $data, ?UploadedFile $image = null, ?User $editor = null): Offer
    {
        $starts = $data['starts_at'] ?? $offer->starts_at;
        $ends = $data['ends_at'] ?? $offer->ends_at;
        $this->assertWithinValidity($offer->business, $starts, $ends);

        if ($image) {
            $this->images->delete($offer->image_path);
            $data['image_path'] = $this->images->store($image, "businesses/{$offer->business_id}/offers");
        }

        // Keep remaining in step with total when the cap changes.
        if (array_key_exists('total_quantity', $data)) {
            $consumed = ($offer->total_quantity ?? 0) - ($offer->remaining_quantity ?? 0);
            $data['remaining_quantity'] = $data['total_quantity'] === null
                ? null
                : max(0, $data['total_quantity'] - $consumed);
        }

        $data['updated_by'] = $editor?->id;
        $offer->update($data);

        return $offer->refresh();
    }

    public function archive(Offer $offer): Offer
    {
        $offer->update(['status' => OfferStatus::Archived]);

        return $offer->refresh();
    }

    public function setStatus(Offer $offer, OfferStatus $status): Offer
    {
        if ($status === OfferStatus::Active) {
            $this->assertActiveQuotaAvailable($offer->business, exceptOfferId: $offer->id);
        }
        $offer->update(['status' => $status]);

        return $offer->refresh();
    }

    public function delete(Offer $offer): void
    {
        $this->images->delete($offer->image_path);
        $offer->delete();
    }

    /** @throws ValidationException */
    private function assertWithinValidity(Business $business, mixed $startsAt, mixed $endsAt): void
    {
        $start = Carbon::parse($startsAt);
        $end = Carbon::parse($endsAt);

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'endsAt' => ['The end date must be after the start date.'],
            ]);
        }

        $plan = $business->currentPlan();
        // A seeded plan governs the cap; a null plan limit means unlimited. Only
        // when no plan exists at all do we fall back to the free default.
        $maxDays = $plan ? $plan->max_offer_days : self::FREE_MAX_VALIDITY_DAYS;

        if ($maxDays !== null && $start->diffInDays($end) > $maxDays) {
            $planName = $plan?->name ?? 'Free';
            throw ValidationException::withMessages([
                'endsAt' => [
                    "Your {$planName} plan allows offers up to {$maxDays} days. Upgrade for longer campaigns.",
                ],
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertActiveQuotaAvailable(Business $business, ?int $exceptOfferId = null): void
    {
        $plan = $business->currentPlan();
        $limit = $plan ? $plan->max_active_offers : self::FREE_MAX_ACTIVE;

        if ($limit === null) {
            return; // unlimited
        }

        $count = $business->offers()->countsAsActive()
            ->when($exceptOfferId, fn ($q) => $q->where('id', '!=', $exceptOfferId))
            ->count();

        if ($count >= $limit) {
            $planName = $plan?->name ?? 'Free';
            throw ValidationException::withMessages([
                'status' => [
                    "Your {$planName} plan allows up to {$limit} active offers. Archive one or upgrade to add more.",
                ],
            ]);
        }
    }
}
