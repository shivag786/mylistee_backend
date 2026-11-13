<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Something the owner should know about (OwnerAlertService). */
class OwnerAlert extends Model
{
    protected $fillable = ['business_id', 'type', 'severity', 'title', 'body', 'data', 'actor_id', 'dedupe_key', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (OwnerAlert $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
