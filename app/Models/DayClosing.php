<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** The end-of-day cash count, with the day's report kept as it was then. */
class DayClosing extends Model
{
    protected $fillable = [
        'business_id',
        'business_date',
        'opening_cash',
        'expected_cash',
        'counted_cash',
        'difference',
        'cash_left',
        'total_sales',
        'orders_count',
        'summary',
        'note',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'cash_left' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'orders_count' => 'integer',
            'summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DayClosing $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
