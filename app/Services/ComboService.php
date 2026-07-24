<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Combo builder service (Phase 7.3). Persists the bundle and its member products
 * (2–3), validating that every product belongs to the same business.
 */
class ComboService
{
    public function __construct(private readonly ImageStorageService $images) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product_id: string, quantity?: int}>  $items
     */
    public function create(Business $business, array $data, array $items, ?UploadedFile $image, User $actor): Combo
    {
        return DB::transaction(function () use ($business, $data, $items, $image, $actor): Combo {
            $combo = new Combo($this->attributes($data));
            $combo->business_id = $business->id;
            $combo->created_by = $actor->id;
            $combo->position = (int) $business->combos()->max('position') + 1;

            if (isset($data['product_category_id'])) {
                $category = $business->productCategories()->where('uuid', $data['product_category_id'])->first();
                $combo->product_category_id = $category?->id;
            }
            if ($image !== null) {
                $combo->image_path = $this->images->store($image, 'combos');
            }

            $combo->save();
            $this->syncItems($business, $combo, $items);

            return $combo->load('items.product');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product_id: string, quantity?: int}>|null  $items
     */
    public function update(Combo $combo, array $data, ?array $items, ?UploadedFile $image, User $actor): Combo
    {
        return DB::transaction(function () use ($combo, $data, $items, $image, $actor): Combo {
            $combo->fill($this->attributes($data));
            $combo->updated_by = $actor->id;

            if (array_key_exists('product_category_id', $data)) {
                $category = $combo->business->productCategories()->where('uuid', $data['product_category_id'])->first();
                $combo->product_category_id = $category?->id;
            }
            if ($image !== null) {
                $this->images->delete($combo->image_path);
                $combo->image_path = $this->images->store($image, 'combos');
            }

            $combo->save();

            if ($items !== null) {
                $this->syncItems($combo->business, $combo, $items);
            }

            return $combo->load('items.product');
        });
    }

    public function delete(Combo $combo): void
    {
        $this->images->delete($combo->image_path);
        $combo->delete();
    }

    /**
     * Replace the combo's items. Enforces the 2–3 product rule and same-business
     * ownership.
     *
     * @param  array<int, array{product_id: string, quantity?: int}>  $items
     *
     * @throws ValidationException
     */
    private function syncItems(Business $business, Combo $combo, array $items): void
    {
        $count = count($items);
        if ($count < 2 || $count > 3) {
            throw ValidationException::withMessages([
                'items' => ['A combo must have 2 or 3 products.'],
            ]);
        }

        $combo->items()->delete();

        foreach ($items as $item) {
            $product = $business->products()->where('uuid', $item['product_id'])->first();
            if ($product === null) {
                throw ValidationException::withMessages([
                    'items' => ['One of the selected products is not in your menu.'],
                ]);
            }
            $combo->items()->create([
                'product_id' => $product->id,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];
        foreach (['name', 'combo_price', 'coins_earned', 'coins_accepted', 'next_visit_coupon', 'bonus_reward', 'starts_at', 'ends_at', 'position'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        foreach (['wallet_coins_accepted', 'auto_enable', 'auto_disable', 'is_visible'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $attributes[$flag] = (bool) $data[$flag];
            }
        }

        return $attributes;
    }
}
