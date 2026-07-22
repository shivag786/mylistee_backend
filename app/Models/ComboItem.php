<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single product within a combo (Phase 7.3).
 */
class ComboItem extends Model
{
    protected $fillable = [
        'combo_id',
        'product_id',
        'quantity',
    ];

    /** @return BelongsTo<Combo, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
