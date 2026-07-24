<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line item within an order (Phase 7.5). Name + unit price are snapshots.
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'combo_id',
        'item_type',
        'name',
        'unit_price',
        'quantity',
        'coins_earned',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lineTotal(): float
    {
        return (float) $this->unit_price * $this->quantity;
    }
}
