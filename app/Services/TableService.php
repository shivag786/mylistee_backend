<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessTable;

/**
 * Owns dining-table CRUD for a business. Tables are optional — a shop with none
 * still takes dine-in orders "to the waiter". Each table's QR is derived from the
 * public profile URL, so nothing image-related is stored here.
 */
class TableService
{
    public function create(Business $business, string $label, ?int $capacity = null): BusinessTable
    {
        $next = (int) $business->tables()->max('sort_order');

        return $business->tables()->create([
            'label' => $label,
            'capacity' => $capacity,
            'sort_order' => $next + 1,
            'status' => 'active',
        ]);
    }

    public function update(BusinessTable $table, string $label, ?int $capacity, string $status): BusinessTable
    {
        $table->update([
            'label' => $label,
            'capacity' => $capacity,
            'status' => $status,
        ]);

        return $table->fresh();
    }

    public function delete(BusinessTable $table): void
    {
        $table->delete();
    }

    /**
     * Persist a new order for the tables by their uuids.
     *
     * @param  array<int, string>  $uuids
     */
    public function reorder(Business $business, array $uuids): void
    {
        foreach (array_values($uuids) as $position => $uuid) {
            $business->tables()->where('uuid', $uuid)->update(['sort_order' => $position + 1]);
        }
    }
}
