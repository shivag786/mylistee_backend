<?php

namespace App\Models;

use App\Enums\CategoryRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An owner-submitted request for a new master category (Phase 7.1).
 */
class CategoryRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'requested_by',
        'business_id',
        'name',
        'sample_image_path',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'created_category_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CategoryRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CategoryRequest $request): void {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<BusinessCategory, $this> */
    public function createdCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'created_category_id');
    }
}
