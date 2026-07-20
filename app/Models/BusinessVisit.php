<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single visit to a business profile (document/phase/02 §Customer Visit).
 * Created by {@see \App\Services\VisitService} on profile view.
 */
class BusinessVisit extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessVisitFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'customer_id',
        'ip_address',
        'device',
        'referrer',
        'source',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessVisit $visit): void {
            if (empty($visit->uuid)) {
                $visit->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
