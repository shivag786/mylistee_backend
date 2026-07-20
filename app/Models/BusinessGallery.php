<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessGallery extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessGalleryFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'business_gallery';

    protected $fillable = [
        'business_id',
        'image_path',
        'sort_order',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (BusinessGallery $image): void {
            if (empty($image->uuid)) {
                $image->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
