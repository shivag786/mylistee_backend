<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One person's attendance for one business day. */
class StaffAttendance extends Model
{
    public const STATUSES = ['present', 'late', 'half_day', 'absent', 'leave', 'week_off'];

    protected $fillable = [
        'business_id',
        'staff_id',
        'date',
        'status',
        'check_in_at',
        'check_out_at',
        'check_in_method',
        'check_out_method',
        'check_in_photo',
        'check_out_photo',
        'check_in_lat',
        'check_in_lng',
        'check_in_distance_m',
        'late_minutes',
        'worked_minutes',
        'note',
        'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_in_distance_m' => 'integer',
            'late_minutes' => 'integer',
            'worked_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StaffAttendance $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<BusinessStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(BusinessStaff::class, 'staff_id')->withTrashed();
    }
}
