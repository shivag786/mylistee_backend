<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QrCode extends Model
{
    /** @use HasFactory<\Database\Factories\QrCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'type',
        'url',
        'image_path',
        'status',
        'download_count',
        'scan_count',
    ];

    protected static function booted(): void
    {
        static::creating(function (QrCode $qr): void {
            if (empty($qr->uuid)) {
                $qr->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
