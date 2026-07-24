<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Business extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'category_id',
        'name',
        'slug',
        'owner_name',
        'description',
        'logo_path',
        'cover_path',
        'address',
        'latitude',
        'longitude',
        'opening_time',
        'closing_time',
        'phone',
        'email',
        'website',
        'facebook',
        'instagram',
        'whatsapp',
        'gst',
        'status',
        'verified',
        'featured',
        'created_by',
        'updated_by',
        // SPEC-011 — Google Business Import fields (URLs/references only).
        'google_business_url',
        'google_place_id',
        'google_rating',
        'google_review_count',
        'google_primary_image_url',
        'google_secondary_image_url',
        'google_category',
        'google_imported_at',
        'google_last_sync',
        'google_sync_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
            'verified' => 'boolean',
            'featured' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'average_rating' => 'decimal:2',
            'google_rating' => 'decimal:2',
            'google_review_count' => 'integer',
            'google_imported_at' => 'datetime',
            'google_last_sync' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business): void {
            if (empty($business->uuid)) {
                $business->uuid = (string) Str::uuid();
            }
            if (empty($business->slug)) {
                $business->slug = self::uniqueSlug($business->name);
            }
        });
    }

    /** Generate a slug unique across businesses (appends -2, -3, … on collision). */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $i = 2;
        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<BusinessCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'category_id');
    }

    /** @return HasMany<BusinessGallery, $this> */
    public function gallery(): HasMany
    {
        return $this->hasMany(BusinessGallery::class)->orderBy('sort_order');
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** Live offers only, highest priority first — the spinner segments. @return HasMany<Offer, $this> */
    public function liveOffers(): HasMany
    {
        return $this->hasMany(Offer::class)->live()->orderByDesc('priority');
    }

    /** Menu products (Phase 7.2). @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Per-business menu sections (Phase 7.2). @return HasMany<ProductCategory, $this> */
    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    /** Promotions in the one engine (Phase 7.2b). @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** Combo bundles (Phase 7.3). @return HasMany<Combo, $this> */
    public function combos(): HasMany
    {
        return $this->hasMany(Combo::class);
    }

    /** Customer orders (Phase 7.5). @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Reward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /** @return HasMany<Spin, $this> */
    public function spins(): HasMany
    {
        return $this->hasMany(Spin::class);
    }

    /** @return HasMany<BusinessVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(BusinessVisit::class);
    }

    /** SPEC-011 §ADMIN LOG — import history for this business. @return HasMany<BusinessImportLog, $this> */
    public function importLogs(): HasMany
    {
        return $this->hasMany(BusinessImportLog::class)->latest();
    }

    /**
     * SPEC-011 §IMAGE POLICY — resolve the cover image to display with the
     * priority owner upload → Google image URL → placeholder. Callers pass the
     * public owner-image URL (or null); returns the URL to render, or null to
     * fall back to a UI placeholder.
     */
    public function displayCoverUrl(?string $ownerUrl): ?string
    {
        return $ownerUrl ?: $this->google_primary_image_url ?: null;
    }

    /** SPEC-011 §IMAGE POLICY — secondary image with the same priority order. */
    public function displaySecondaryUrl(?string $ownerUrl): ?string
    {
        return $ownerUrl ?: $this->google_secondary_image_url ?: null;
    }

    /** Per-business loyalty configuration (null earn rates fall back to config). @return HasOne<LoyaltyProgram, $this> */
    public function loyaltyProgram(): HasOne
    {
        return $this->hasOne(LoyaltyProgram::class);
    }

    /** Reward tiers customers can spend coins on. @return HasMany<LoyaltyReward, $this> */
    public function loyaltyRewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class)->orderBy('sort_order')->orderBy('coins_cost');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    /** The current active, unexpired subscription, or null (Milestone 13). */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->where(function ($q): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->with('plan')
            ->first();
    }

    private ?Plan $resolvedPlan = null;

    /**
     * The plan currently governing this business — the active subscription's
     * plan, or the default (free) plan when there is none. Cached per instance.
     */
    public function currentPlan(): ?Plan
    {
        return $this->resolvedPlan ??= ($this->activeSubscription()?->plan ?? Plan::default());
    }

    /** Drop the cached currentPlan() after a subscription change. */
    public function forgetPlanCache(): void
    {
        $this->resolvedPlan = null;
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<Favorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** Recompute cached rating aggregates from published reviews. */
    public function recalculateRating(): void
    {
        $stats = $this->reviews()->where('status', 'published')
            ->selectRaw('COUNT(*) as c, COALESCE(AVG(rating),0) as a')->first();

        $this->forceFill([
            'total_reviews' => (int) ($stats->c ?? 0),
            'average_rating' => round((float) ($stats->a ?? 0), 2),
        ])->save();
    }

    /** @return HasOne<QrCode, $this> */
    public function qrCode(): HasOne
    {
        return $this->hasOne(QrCode::class)->where('type', 'primary');
    }

    public function isOpenNow(): bool
    {
        if (! $this->opening_time || ! $this->closing_time) {
            return false;
        }

        $now = now()->format('H:i:s');
        $open = $this->normaliseTime((string) $this->opening_time);
        $close = $this->normaliseTime((string) $this->closing_time);

        if ($open === $close) {
            return true; // open 24 hours
        }

        // Overnight windows (e.g. 18:00–02:00) wrap past midnight.
        return $close > $open
            ? ($now >= $open && $now <= $close)
            : ($now >= $open || $now <= $close);
    }

    /** Coerce a time value to "H:i:s" for safe string comparison. */
    private function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
