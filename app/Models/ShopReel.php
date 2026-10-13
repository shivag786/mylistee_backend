<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A short video, or a few photos played as a reel, posted by a shop. */
class ShopReel extends Model
{
    protected $fillable = [
        'business_id',
        'type',
        'video_path',
        'poster_path',
        'photo_paths',
        'caption',
        'product_id',
        'duration_seconds',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'photo_paths' => 'array',
            'is_visible' => 'boolean',
            'duration_seconds' => 'integer',
            'views' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopReel $reel): void {
            if (empty($reel->uuid)) {
                $reel->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** The dish the reel shows, if the owner linked one. @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
