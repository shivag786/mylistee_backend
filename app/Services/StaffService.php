<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The owner's staff: adding people, what each may do, their shift and pay,
 * turning access off — and staff signing in with the shop code + PIN.
 */
class StaffService
{
    private const PIN_MAX_ATTEMPTS = 5;

    private const PIN_DECAY_SECONDS = 900;

    public function __construct(
        private readonly OwnerAlertService $alerts,
        private readonly ModuleUsageService $usage,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public static function permissionList(): array
    {
        return (array) config('staff_permissions.permissions', []);
    }

    /** @return array<string, array<string, mixed>> */
    public static function roles(): array
    {
        return (array) config('staff_permissions.roles', []);
    }

    /**
     * @param  array<string, mixed>  $data  validated (StaffController)
     */
    public function create(Business $business, array $data): BusinessStaff
    {
        $phone = self::phone((string) $data['phone']);
        if ($business->staff()->withTrashed()->where('phone', $phone)->exists()) {
            $existing = $business->staff()->withTrashed()->where('phone', $phone)->first();
            if ($existing->trashed()) {
                // Re-hiring someone removed earlier: bring their record back.
                $existing->restore();
                $existing->user?->forceFill(['status' => UserStatus::Active])->save();

                return $this->update($existing, $data + ['active' => true]);
            }
            throw ValidationException::withMessages(['phone' => ['This mobile is already on your staff list.']]);
        }

        $role = (string) ($data['role'] ?? 'waiter');
        $preset = self::roles()[$role] ?? self::roles()['other'];

        return DB::transaction(function () use ($business, $data, $phone, $role, $preset): BusinessStaff {
            $user = User::create([
                'name' => $data['name'],
                'pin' => $data['pin'],
                'role' => UserRole::Staff,
                'status' => UserStatus::Active,
                'provider' => 'staff',
            ]);

            $staff = $business->staff()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => $phone,
                'role' => $role,
                'permissions' => $this->cleanPermissions($data['permissions'] ?? $preset['permissions']),
                'max_discount_percent' => array_key_exists('maxDiscountPercent', $data) ? $data['maxDiscountPercent'] : $preset['maxDiscount'],
                'shift_start' => $data['shiftStart'] ?? null,
                'shift_end' => $data['shiftEnd'] ?? null,
                'week_off' => array_values(array_map('intval', $data['weekOff'] ?? [])),
                'monthly_salary' => $data['monthlySalary'] ?? null,
                'joined_on' => $data['joinedOn'] ?? now()->toDateString(),
                'active' => true,
            ]);
            $business->staffCode();
            $this->usage->record($business, 'staff', 'staff:'.$staff->id);

            return $staff;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(BusinessStaff $staff, array $data): BusinessStaff
    {
        $fields = [];
        $map = [
            'name' => 'name', 'role' => 'role', 'maxDiscountPercent' => 'max_discount_percent',
            'shiftStart' => 'shift_start', 'shiftEnd' => 'shift_end', 'monthlySalary' => 'monthly_salary',
            'joinedOn' => 'joined_on', 'active' => 'active',
        ];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }
        if (array_key_exists('phone', $data)) {
            $phone = self::phone((string) $data['phone']);
            if ($phone !== $staff->phone && BusinessStaff::withTrashed()->where('business_id', $staff->business_id)->where('phone', $phone)->exists()) {
                throw ValidationException::withMessages(['phone' => ['This mobile is already on your staff list.']]);
            }
            $fields['phone'] = $phone;
        }
        if (array_key_exists('weekOff', $data)) {
            $fields['week_off'] = array_values(array_map('intval', $data['weekOff'] ?? []));
        }
        if (array_key_exists('permissions', $data)) {
            $fields['permissions'] = $this->cleanPermissions($data['permissions'] ?? []);
        } elseif (array_key_exists('role', $data) && $data['role'] !== $staff->role) {
            // A new role without ticks given: start from that role's set.
            $preset = self::roles()[$data['role']] ?? self::roles()['other'];
            $fields['permissions'] = $preset['permissions'];
            if (! array_key_exists('maxDiscountPercent', $data)) {
                $fields['max_discount_percent'] = $preset['maxDiscount'];
            }
        }

        DB::transaction(function () use ($staff, $fields, $data): void {
            $staff->update($fields);
            $user = $staff->user;
            if ($user !== null) {
                $userFields = [];
                if (isset($fields['name'])) {
                    $userFields['name'] = $fields['name'];
                }
                if (! empty($data['pin'])) {
                    $userFields['pin'] = $data['pin'];
                }
                if (array_key_exists('active', $fields)) {
                    $userFields['status'] = $fields['active'] ? UserStatus::Active : UserStatus::Suspended;
                }
                if ($userFields !== []) {
                    $user->forceFill($userFields)->save();
                }
                // Access off or a new PIN: sign them out everywhere.
                if ((array_key_exists('active', $fields) && ! $fields['active']) || ! empty($data['pin'])) {
                    $user->tokens()->delete();
                }
            }
        });

        return $staff->fresh();
    }

    /** Remove from the list (kept for past attendance and orders); signs them out. */
    public function remove(BusinessStaff $staff): void
    {
        DB::transaction(function () use ($staff): void {
            $staff->update(['active' => false]);
            $staff->user?->forceFill(['status' => UserStatus::Suspended])->save();
            $staff->user?->tokens()->delete();
            $staff->delete();
        });
    }

    /**
     * Staff sign-in: the shop's code, their mobile and their PIN. Locks after
     * five wrong tries for that mobile at that shop, like the owner sign-in.
     *
     * @return array{user: User, token: string}
     */
    public function login(string $shopCode, string $phone, string $pin): array
    {
        $code = strtoupper(trim($shopCode));
        $phone = self::phone($phone);
        $key = 'staff-login:'.$code.':'.$phone;

        if (RateLimiter::tooManyAttempts($key, self::PIN_MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['pin' => ["Too many attempts. Please try again in {$minutes} minutes."]]);
        }

        $business = Business::where('staff_code', $code)->first();
        $staff = $business?->staff()->where('phone', $phone)->with('user')->first();
        $user = $staff?->user;

        if ($user === null || $user->pin === null || ! Hash::check($pin, $user->pin)) {
            RateLimiter::hit($key, self::PIN_DECAY_SECONDS);
            throw ValidationException::withMessages(['pin' => ['Wrong shop code, mobile number or PIN.']]);
        }
        RateLimiter::clear($key);

        if (! $staff->active || $user->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['pin' => ['Your access to this shop is turned off. Please ask the owner.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->alerts->raise($business, 'staff_login', "{$staff->name} signed in", 'On the Listee vendor app.', 'info', [], $user);

        return ['user' => $user, 'token' => $user->createToken('staff')->plainTextToken];
    }

    /** Check a staff member's PIN (the attendance tablet). */
    public function checkPin(BusinessStaff $staff, string $pin): void
    {
        $key = 'staff-pin:'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, self::PIN_MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['pin' => ['Too many wrong PINs. Try again in a few minutes.']]);
        }
        $user = $staff->user;
        if ($user === null || $user->pin === null || ! Hash::check($pin, $user->pin)) {
            RateLimiter::hit($key, self::PIN_DECAY_SECONDS);
            throw ValidationException::withMessages(['pin' => ['Wrong PIN.']]);
        }
        RateLimiter::clear($key);
    }

    /** @return array<string, mixed> */
    public function payload(BusinessStaff $staff): array
    {
        return [
            'id' => $staff->uuid,
            'name' => $staff->name,
            'phone' => $staff->phone,
            'role' => $staff->role,
            'roleName' => self::roles()[$staff->role]['name'] ?? ucfirst($staff->role),
            'permissions' => array_values($staff->permissions ?? []),
            'maxDiscountPercent' => $staff->max_discount_percent !== null ? (float) $staff->max_discount_percent : null,
            'shiftStart' => $staff->shift_start ? substr((string) $staff->shift_start, 0, 5) : null,
            'shiftEnd' => $staff->shift_end ? substr((string) $staff->shift_end, 0, 5) : null,
            'weekOff' => array_values(array_map('intval', $staff->week_off ?? [])),
            'monthlySalary' => $staff->monthly_salary !== null ? (float) $staff->monthly_salary : null,
            'joinedOn' => $staff->joined_on?->toDateString(),
            'active' => (bool) $staff->active,
            'lastLoginAt' => $staff->user?->last_login_at?->toIso8601String(),
        ];
    }

    /** 10-digit Indian mobile from whatever was typed. */
    public static function phone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    private function cleanPermissions(array $permissions): array
    {
        return array_values(array_intersect(array_keys(self::permissionList()), array_map('strval', $permissions)));
    }
}
