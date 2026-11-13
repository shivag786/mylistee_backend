<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Cash put into or taken out of the drawer (change float, paying a supplier…). */
class CashMovement extends Model
{
    protected $fillable = ['business_id', 'business_date', 'type', 'amount', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (CashMovement $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
