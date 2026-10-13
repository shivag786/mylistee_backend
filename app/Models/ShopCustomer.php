<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer in one shop's own customer book, known by mobile number. Not a
 * platform account — see the create_shop_customers_table migration.
 */
class ShopCustomer extends Model
{
    /** @use HasFactory<\Database\Factories\ShopCustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'phone',
        'name',
        'email',
        'city',
        'source',
    ];

    protected static function booted(): void
    {
        static::creating(function (ShopCustomer $customer): void {
            if (empty($customer->uuid)) {
                $customer->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Reduce a typed mobile number to its 10 digits: drops spaces, dashes, a
     * leading 0 and a +91/91 country code. Returns null when what's left isn't
     * a valid Indian mobile number.
     */
    public static function normalizePhone(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
