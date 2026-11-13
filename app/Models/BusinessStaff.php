<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A person working at a shop. They sign in to the vendor app as their own
 * user (role `staff`, with no phone or email on the user row, so they never
 * clash with a customer account) using the shop's code, their mobile and a PIN.
 *
 * `permissions` lists what the owner allowed (config/staff_permissions.php);
 * a role is only a starting set of ticks.
 */
class BusinessStaff extends Model
{
    use SoftDeletes;

    protected $table = 'business_staff';

    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'phone',
        'role',
        'permissions',
        'max_discount_percent',
        'shift_start',
        'shift_end',
        'week_off',
        'monthly_salary',
        'joined_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'max_discount_percent' => 'decimal:2',
            'week_off' => 'array',
            'monthly_salary' => 'decimal:2',
            'joined_on' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessStaff $staff): void {
            if (empty($staff->uuid)) {
                $staff->uuid = (string) Str::uuid();
            }
        });
    }

    public function allows(string $permission): bool
    {
        return $this->active && in_array($permission, $this->permissions ?? [], true);
    }

    /** Is [$isoWeekday] (1 = Monday) this person's day off? */
    public function isWeekOff(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', $this->week_off ?? []), true);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StaffAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class, 'staff_id');
    }
}
