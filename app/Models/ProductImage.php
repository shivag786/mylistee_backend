<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A gallery image belonging to a product (Phase 7.2).
 */
class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'image_path',
        'position',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductImage $image): void {
            if (empty($image->uuid)) {
                $image->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
