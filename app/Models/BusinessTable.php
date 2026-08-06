<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A dining table for a business. A dine-in order may bind to one (or to none —
 * "order to the waiter"). The QR is derived, not stored: {profile}?table={uuid}.
 */
class BusinessTable extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'label',
        'capacity',
        'sort_order',
        'scan_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'scan_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessTable $table): void {
            if (empty($table->uuid)) {
                $table->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
