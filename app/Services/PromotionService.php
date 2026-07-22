<?php

namespace App\Services;

use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Models\Business;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The promotion engine's service layer (Phase 7.2b, 07A). Owns create/update,
 * status transitions (manual pause/resume + automatic start/stop), and the
 * initial-status calculation from the schedule.
 */
class PromotionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Business $business, array $data, User $actor, ?Product $product = null): Promotion
    {
        $promotion = new Promotion($this->attributes($data));
        $promotion->business_id = $business->id;
        $promotion->product_id = $product?->id;
        $promotion->created_by = $actor->id;
        // Apply the schema defaults before deriving status (unsaved model).
        $promotion->auto_start ??= true;
        $promotion->auto_stop ??= true;
        $promotion->status = $this->initialStatus($promotion);
        $promotion->save();

        return $promotion->fresh(['product']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Promotion $promotion, array $data, User $actor): Promotion
    {
        $promotion->fill($this->attributes($data));
        $promotion->updated_by = $actor->id;

        // Re-evaluate an automatic status unless the owner has paused it.
        if ($promotion->status !== PromotionStatus::Paused) {
            $promotion->status = $this->initialStatus($promotion);
        }
        $promotion->save();

        return $promotion->fresh(['product']);
    }

    public function pause(Promotion $promotion): Promotion
    {
        $promotion->update(['status' => PromotionStatus::Paused]);

        return $promotion;
    }

    /** Resume a paused promotion, recomputing whether it should run now. */
    public function resume(Promotion $promotion): Promotion
    {
        $promotion->update(['status' => $this->initialStatus($promotion)]);

        return $promotion;
    }

    public function delete(Promotion $promotion): void
    {
        $promotion->delete();
    }

    /**
     * Advance automatic promotions (called by `promotions:tick` every minute).
     * Scheduled → Running when the start time passes; Running/Scheduled → Expired
     * when the end time passes. Returns the number of rows changed.
     */
    public function tick(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $changed = 0;

        // Start due promotions.
        $toStart = Promotion::query()
            ->where('status', PromotionStatus::Scheduled)
            ->where('auto_start', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();
        foreach ($toStart as $promotion) {
            $promotion->update(['status' => PromotionStatus::Running]);
            $changed++;
        }

        // Expire finished promotions.
        $toExpire = Promotion::query()
            ->whereIn('status', [PromotionStatus::Running, PromotionStatus::Scheduled])
            ->where('auto_stop', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->get();
        foreach ($toExpire as $promotion) {
            $promotion->update(['status' => PromotionStatus::Expired]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Decide the status a promotion should have based on its schedule.
     */
    private function initialStatus(Promotion $promotion): PromotionStatus
    {
        $now = Carbon::now();

        if ($promotion->ends_at !== null && $now->gt($promotion->ends_at)) {
            return PromotionStatus::Expired;
        }
        if (! $promotion->auto_start) {
            return PromotionStatus::Draft;
        }
        if ($promotion->starts_at !== null && $now->lt($promotion->starts_at)) {
            return PromotionStatus::Scheduled;
        }

        return PromotionStatus::Running;
    }

    /**
     * Normalise validated input into model attributes, building the type-specific
     * `config` blob from the discount fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        foreach (['promotion_type', 'name', 'starts_at', 'ends_at', 'daily_start_time', 'daily_end_time', 'priority'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        foreach (['auto_start', 'auto_stop'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $attributes[$flag] = (bool) $data[$flag];
            }
        }

        // Build config from the flat discount inputs.
        $type = isset($data['promotion_type']) ? PromotionType::from($data['promotion_type']) : null;
        $config = [];
        if (array_key_exists('discount_type', $data)) {
            $config['discount_type'] = $data['discount_type'];
        }
        if (array_key_exists('value', $data) && $data['value'] !== null && $data['value'] !== '') {
            $config['value'] = (float) $data['value'];
        }
        if (array_key_exists('buy_qty', $data) && $data['buy_qty'] !== null && $data['buy_qty'] !== '') {
            $config['buy_qty'] = (int) $data['buy_qty'];
        }
        if (array_key_exists('get_qty', $data) && $data['get_qty'] !== null && $data['get_qty'] !== '') {
            $config['get_qty'] = (int) $data['get_qty'];
        }
        if (array_key_exists('min_qty', $data) && $data['min_qty'] !== null && $data['min_qty'] !== '') {
            $config['min_qty'] = (int) $data['min_qty'];
        }

        // Percentage / flat types imply their own discount_type.
        if ($type === PromotionType::Percentage) {
            $config['discount_type'] = 'percentage';
        } elseif ($type === PromotionType::Flat) {
            $config['discount_type'] = 'flat';
        }

        if ($config !== []) {
            $attributes['config'] = $config;
        }

        return $attributes;
    }
}
