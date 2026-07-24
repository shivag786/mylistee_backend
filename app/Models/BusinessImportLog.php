<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SPEC-011 §ADMIN LOG — one record per import attempt. Append-only from the app.
 */
class BusinessImportLog extends Model
{
    protected $fillable = [
        'business_id',
        'imported_by',
        'source',
        'source_url',
        'place_id',
        'status',
        'updated_fields',
        'message',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'updated_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessImportLog $log): void {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
