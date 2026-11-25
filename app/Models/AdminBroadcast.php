<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One admin push broadcast and its delivery result. */
class AdminBroadcast extends Model
{
    protected $fillable = [
        'title', 'body', 'link', 'target', 'business_id', 'audience', 'category_id',
        'recipients', 'reachable', 'pushed', 'failed', 'status', 'created_by', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AdminBroadcast $b): void {
            if (empty($b->uuid)) {
                $b->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<BusinessCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }
}
