<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Spin extends Model
{
    /** @use HasFactory<\Database\Factories\SpinFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'business_id',
        'offer_id',
        'reward_id',
        'ip_address',
        'device',
    ];

    protected static function booted(): void
    {
        static::creating(function (Spin $spin): void {
            if (empty($spin->uuid)) {
                $spin->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
