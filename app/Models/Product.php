<?php

namespace App\Models;

use App\Enums\FoodType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A catalogue / menu product (Phase 7.2).
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'product_category_id',
        'name',
        'description',
        'ingredients',
        'image_path',
        'mrp',
        'selling_price',
        'food_type',
        'available_from',
        'available_to',
        'prep_minutes',
        'is_todays_special',
        'is_bestseller',
        'is_recommended',
        'in_stock',
        'is_visible',
        'position',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'food_type' => FoodType::class,
            'mrp' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_todays_special' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_recommended' => 'boolean',
            'in_stock' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /** Product-scoped promotions / smart offers (Phase 7.2b). @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * The best currently-active price promotion for this product, or null. Uses
     * the loaded `promotions` relation when present to avoid extra queries.
     */
    public function activePromotion(): ?Promotion
    {
        $base = (float) $this->selling_price;

        return $this->promotions
            ->filter(fn (Promotion $p) => $p->isActiveNow() && $p->discountAmount($base) > 0)
            ->sortByDesc('priority')
            ->first();
    }

    /** Current effective unit price after the best active promotion. */
    public function effectivePrice(): float
    {
        $base = (float) $this->selling_price;
        $promotion = $this->activePromotion();

        return $promotion === null ? $base : round($base - $promotion->discountAmount($base), 2);
    }
}
