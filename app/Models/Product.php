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
        'options',
        'food_type',
        'available_from',
        'available_to',
        'prep_minutes',
        'is_todays_special',
        'is_bestseller',
        'is_recommended',
        'in_stock',
        'stock_qty',
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
            'options' => 'array',
            'is_todays_special' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_recommended' => 'boolean',
            'in_stock' => 'boolean',
            'stock_qty' => 'integer',
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

    /** Order lines referencing this product — powers the "most ordered" badge. @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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

    /**
     * The best currently-active promotion of ANY type, or null — including
     * BOGO / quantity discounts that don't change the unit price. Used to display
     * the offer (type + label) even when there's no per-unit price to strike.
     */
    public function activeDisplayPromotion(): ?Promotion
    {
        return $this->promotions
            ->filter(fn (Promotion $p) => $p->isActiveNow())
            ->sortByDesc('priority')
            ->first();
    }

    /**
     * Sizes the customer picks one of ("Small", "Large"), each with its own
     * price before any discount.
     *
     * @return list<array{id: string, name: string, price: float}>
     */
    public function variants(): array
    {
        return array_values($this->options['variants'] ?? []);
    }

    /**
     * How a size reads to people: "Fried · Half · 6 pcs" (type, size and
     * quantity, whichever the owner filled in).
     *
     * @param  array<string, mixed>  $variant
     */
    public static function variantLabel(array $variant): string
    {
        return implode(' · ', array_filter([
            $variant['type'] ?? null,
            $variant['name'] ?? null,
            $variant['qty'] ?? null,
        ], fn ($v) => filled($v)));
    }

    /**
     * Extras the customer may add any of ("Extra cheese"), each priced on top.
     *
     * @return list<array{id: string, name: string, price: float}>
     */
    public function addons(): array
    {
        return array_values($this->options['addons'] ?? []);
    }

    /** @return array{type: string, value: float}|null  type: percent | flat */
    public function discount(): ?array
    {
        $discount = $this->options['discount'] ?? null;

        return is_array($discount) && (float) ($discount['value'] ?? 0) > 0 ? $discount : null;
    }

    /** A price after this product's own % / ₹ discount (never below zero). */
    public function applyDiscount(float $price): float
    {
        $discount = $this->discount();
        if ($discount === null) {
            return $price;
        }
        $off = $discount['type'] === 'percent'
            ? $price * min(100.0, (float) $discount['value']) / 100
            : (float) $discount['value'];

        return round(max(0.0, $price - $off), 2);
    }

    /**
     * What one unit costs with the customer's picks: the chosen size (or the
     * plain price), less any running promotion, plus the add-ons.
     *
     * @param  list<string>  $addonIds
     *
     * @throws \InvalidArgumentException when a pick isn't on this product
     */
    public function priceFor(?string $variantId, array $addonIds = []): float
    {
        $variants = $this->variants();
        if ($variants === []) {
            $base = (float) $this->selling_price;
        } else {
            $variant = collect($variants)->firstWhere('id', $variantId);
            if ($variant === null) {
                throw new \InvalidArgumentException('Choose a size.');
            }
            $base = $this->applyDiscount((float) $variant['price']);
        }

        $promotion = $this->activePromotion();
        $unit = $promotion === null ? $base : round($base - $promotion->discountAmount($base), 2);

        $addons = collect($this->addons())->keyBy('id');
        foreach (array_unique($addonIds) as $id) {
            if (! $addons->has($id)) {
                throw new \InvalidArgumentException('An add-on is no longer available.');
            }
            $unit += (float) $addons[$id]['price'];
        }

        return round($unit, 2);
    }
}
