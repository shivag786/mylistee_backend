<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One push notification a shop sent to a slice of its customers. */
class ShopPushCampaign extends Model
{
    protected $fillable = [
        'business_id',
        'audience',
        'title',
        'body',
        'link',
        'recipients',
        'reachable',
        'sent_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
            'reachable' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShopPushCampaign $campaign): void {
            $campaign->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
