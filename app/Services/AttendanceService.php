<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessStaff;
use App\Models\StaffAttendance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Staff attendance, two ways (the owner picks one or both):
 *
 *  - Own phone: the staff member taps Check in; the app sends a front-camera
 *    selfie and the phone's GPS, which must be within the shop's radius.
 *  - Shop tablet ("kiosk"): the counter device, signed in as the owner or a
 *    manager, shows the staff list; each person taps their name, types their
 *    PIN and the tablet takes the selfie.
 *
 * The selfie is proof the owner can look at, not face recognition — no
 * biometric data is kept, and nobody is turned away by a dim kitchen light.
 *
 * Late = checked in after shift start + grace minutes. Fewer worked hours
 * than the half-day mark = half day. A day with nothing marked counts as
 * absent once it has passed (or week off, on the person's weekly off).
 */
class AttendanceService
{
    /** A check-in this old with no check-out is left open (forgot to check out). */
    private const OPEN_HOURS = 20;

    public function __construct(
        private readonly OwnerAlertService $alerts,
        private readonly ModuleUsageService $usage,
    ) {}

    /**
     * Check in, or check out of the open check-in.
     *
     * @return array{action: string, attendance: StaffAttendance}
     */
    public function punch(BusinessStaff $staff, string $method, ?UploadedFile $photo, ?float $lat, ?float $lng, ?float $accuracy): array
    {
        $business = $staff->business;
        $rules = $business->posSettings()->attendance();

        if ($method === 'self' && ! $rules['selfPunch']) {
            throw ValidationException::withMessages(['method' => ['Attendance is taken on the shop\'s tablet. Please punch there.']]);
        }
        if ($method === 'kiosk' && ! $rules['kiosk']) {
            throw ValidationException::withMessages(['method' => ['The shop tablet is not used for attendance.']]);
        }
        if ($rules['requirePhoto'] && $photo === null) {
            throw ValidationException::withMessages(['photo' => ['A selfie is needed to check in or out.']]);
        }

        $distance = null;
        if ($method === 'self') {
            $distance = $this->checkDistance($business, $lat, $lng, $accuracy, (int) $rules['radiusMeters']);
        }

        $now = Carbon::now();
        $open = $staff->attendances()
            ->whereNotNull('check_in_at')->whereNull('check_out_at')
            ->where('check_in_at', '>=', $now->copy()->subHours(self::OPEN_HOURS))
            ->latest('check_in_at')->first();

        $path = $photo ? $photo->store("attendance/{$business->id}", 'local') : null;

        if ($open !== null) {
            $worked = (int) $open->check_in_at->diffInMinutes($now);
            $status = $open->status;
            if (in_array($status, ['present', 'late'], true) && $worked < (float) $rules['halfDayHours'] * 60) {
                $status = 'half_day';
            }
            $open->update([
                'check_out_at' => $now,
                'check_out_method' => $method,
                'check_out_photo' => $path,
                'worked_minutes' => $worked,
                'status' => $status,
            ]);

            return ['action' => 'out', 'attendance' => $open->fresh()];
        }

        $date = $business->businessDateOf($now);
        $existing = $staff->attendances()->whereDate('date', $date)->first();
        if ($existing !== null && $existing->check_out_at !== null) {
            throw ValidationException::withMessages(['punch' => ['You have already checked out today. Ask the owner to correct it if needed.']]);
        }

        $late = $this->lateMinutes($staff, $date, $now, (int) $rules['graceMinutes']);
        $row = $existing ?? new StaffAttendance(['business_id' => $business->id, 'staff_id' => $staff->id, 'date' => $date]);
        $row->fill([
            'status' => $late > 0 ? 'late' : 'present',
            'check_in_at' => $now,
            'check_in_method' => $method,
            'check_in_photo' => $path,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'check_in_distance_m' => $distance,
            'late_minutes' => $late,
        ])->save();

        $this->usage->record($business, 'attendance', 'staff:'.$staff->id);

        return ['action' => 'in', 'attendance' => $row->fresh()];
    }

    /**
     * The owner sets a day by hand: present, leave, absent… with times if known.
     *
     * @param  array{status: string, checkIn?: ?string, checkOut?: ?string, note?: ?string}  $data  times as H:i, shop time
     */
    public function mark(BusinessStaff $staff, string $date, array $data, User $actor): StaffAttendance
    {
        $zone = config('app.business_timezone', 'Asia/Kolkata');
        $row = $staff->attendances()->whereDate('date', $date)->first()
            ?? new StaffAttendance(['business_id' => $staff->business_id, 'staff_id' => $staff->id, 'date' => $date]);

        $in = ! empty($data['checkIn']) ? Carbon::parse("{$date} {$data['checkIn']}", $zone)->utc() : ($row->check_in_at ?? null);
        $out = ! empty($data['checkOut']) ? Carbon::parse("{$date} {$data['checkOut']}", $zone)->utc() : ($row->check_out_at ?? null);
        if ($in !== null && $out !== null && $out->lessThan($in)) {
            $out = $out->copy()->addDay(); // a night shift ending after midnight
        }
        if (in_array($data['status'], ['absent', 'leave', 'week_off'], true)) {
            $in = $out = null;
        }

        $row->fill([
            'status' => $data['status'],
            'check_in_at' => $in,
            'check_out_at' => $out,
            'check_in_method' => $in ? ($row->check_in_method ?? 'manual') : null,
            'check_out_method' => $out ? ($row->check_out_method ?? 'manual') : null,
            'worked_minutes' => $in && $out ? (int) $in->diffInMinutes($out) : null,
            'late_minutes' => $data['status'] === 'late' && $in
                ? max(1, $this->lateMinutes($staff, $date, $in, 0))
                : ($data['status'] === 'late' ? max(1, (int) $row->late_minutes) : 0),
            'note' => $data['note'] ?? $row->note,
            'marked_by' => $actor->id,
        ])->save();

        return $row->fresh();
    }

    /**
     * Everyone's day at a glance: who is in, late, not in yet, off.
     *
     * @return list<array<string, mixed>>
     */
    public function day(Business $business, string $date): array
    {
        $today = $business->businessDateOf();
        $grace = (int) $business->posSettings()->attendance()['graceMinutes'];
        $rows = StaffAttendance::where('business_id', $business->id)->whereDate('date', $date)->get()->keyBy('staff_id');
        $staffList = $business->staff()->withTrashed()
            ->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at')->orWhereIn('id', $rows->keys()))
            ->orderBy('name')->get();

        return $staffList->map(function (BusinessStaff $staff) use ($rows, $date, $today, $grace) {
            $row = $rows[$staff->id] ?? null;
            $status = $row?->status;
            if ($status === null) {
                $weekday = Carbon::parse($date)->dayOfWeekIso;
                $status = match (true) {
                    $staff->isWeekOff($weekday) => 'week_off',
                    $date < $today => 'absent',
                    $date > $today => 'upcoming',
                    $staff->shift_start !== null && $this->lateMinutes($staff, $date, Carbon::now(), $grace) > 0 => 'not_in',
                    default => 'yet',
                };
            }

            return [
                'staff' => ['id' => $staff->uuid, 'name' => $staff->name, 'role' => $staff->role, 'shiftStart' => $staff->shift_start ? substr((string) $staff->shift_start, 0, 5) : null, 'shiftEnd' => $staff->shift_end ? substr((string) $staff->shift_end, 0, 5) : null],
                'status' => $status,
            ] + ($row ? $this->payload($row) : ['id' => null]);
        })->values()->all();
    }

    /**
     * A month per person: days present / late / half / leave / off / absent,
     * hours, and the salary those days come to (an estimate the owner checks).
     *
     * @return list<array<string, mixed>>
     */
    public function month(Business $business, string $month, ?BusinessStaff $only = null): array
    {
        $start = Carbon::parse($month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = $business->businessDateOf();
        $daysInMonth = $start->daysInMonth;

        $staffList = $only ? collect([$only]) : $business->staff()->withTrashed()
            ->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at')
                ->orWhereHas('attendances', fn ($a) => $a->whereBetween('date', [$start->toDateString(), $end->toDateString().' 23:59:59'])))
            ->orderBy('name')->get();

        $rows = StaffAttendance::where('business_id', $business->id)
            ->whereIn('staff_id', $staffList->pluck('id'))
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
            ->get()->groupBy('staff_id');

        return $staffList->map(function (BusinessStaff $staff) use ($rows, $start, $end, $today, $daysInMonth) {
            $byDate = ($rows[$staff->id] ?? collect())->keyBy(fn ($r) => $r->date->toDateString());
            $counts = ['present' => 0, 'late' => 0, 'half_day' => 0, 'leave' => 0, 'week_off' => 0, 'absent' => 0];
            $worked = 0;
            $lateMinutes = 0;
            $days = [];
            $from = $staff->joined_on && $staff->joined_on->greaterThan($start) ? $staff->joined_on->copy() : $start->copy();

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $key = $d->toDateString();
                $row = $byDate[$key] ?? null;
                if ($row !== null) {
                    $status = $row->status;
                    $worked += (int) $row->worked_minutes;
                    $lateMinutes += (int) $row->late_minutes;
                } elseif ($d->lt($from) || $key >= $today) {
                    $status = null; // before joining, or not over yet
                } else {
                    $status = $staff->isWeekOff($d->dayOfWeekIso) ? 'week_off' : 'absent';
                }
                if ($status !== null && isset($counts[$status])) {
                    $counts[$status]++;
                }
                $days[] = ['date' => $key, 'status' => $status] + ($row ? [
                    'checkIn' => $row->check_in_at?->toIso8601String(),
                    'checkOut' => $row->check_out_at?->toIso8601String(),
                    'lateMinutes' => (int) $row->late_minutes,
                    'workedMinutes' => $row->worked_minutes,
                ] : []);
            }

            // Week off and leave are paid; a half day is half.
            $payable = $counts['present'] + $counts['late'] + $counts['leave'] + $counts['week_off'] + $counts['half_day'] * 0.5;
            $salary = $staff->monthly_salary !== null ? round((float) $staff->monthly_salary / $daysInMonth * $payable, 2) : null;

            return [
                'staff' => ['id' => $staff->uuid, 'name' => $staff->name, 'role' => $staff->role, 'monthlySalary' => $staff->monthly_salary !== null ? (float) $staff->monthly_salary : null],
                'counts' => $counts,
                'workedHours' => round($worked / 60, 1),
                'lateMinutes' => $lateMinutes,
                'payableDays' => $payable,
                'salaryEstimate' => $salary,
                'days' => $days,
            ];
        })->values()->all();
    }

    /**
     * For the scheduler: tell the owner once about anyone not in after their
     * shift start + grace (not on a week off, no leave marked).
     */
    public function lateCheck(Business $business): void
    {
        $date = $business->businessDateOf();
        $grace = (int) $business->posSettings()->attendance()['graceMinutes'];
        $marked = StaffAttendance::where('business_id', $business->id)->whereDate('date', $date)->pluck('staff_id')->all();
        $weekday = Carbon::parse($date)->dayOfWeekIso;

        $missing = $business->staff()->where('active', true)->whereNotNull('shift_start')
            ->whereNotIn('id', $marked)->get()
            ->filter(fn (BusinessStaff $s) => ! $s->isWeekOff($weekday)
                && $this->lateMinutes($s, $date, Carbon::now(), $grace) > 0
                && Cache::add("attendance-late:{$s->id}:{$date}", 1, now()->addDay()));

        if ($missing->isEmpty()) {
            return;
        }
        $names = $missing->map(fn ($s) => $s->name.' ('.substr((string) $s->shift_start, 0, 5).')')->join(', ');
        $this->alerts->raise(
            $business,
            'attendance',
            $missing->count() === 1 ? "{$missing->first()->name} hasn't checked in" : $missing->count().' staff haven\'t checked in',
            "Not in yet: {$names}.",
            'warning',
            ['date' => $date],
            dedupeKey: 'late:'.$date.':'.$missing->pluck('id')->sort()->join('-'),
        );
    }

    /** @return array<string, mixed> */
    public function payload(StaffAttendance $row): array
    {
        $photo = fn (string $which) => route('business.attendance.photo', ['uuid' => $row->uuid, 'which' => $which], false);

        return [
            'id' => $row->uuid,
            'date' => $row->date->toDateString(),
            'status' => $row->status,
            'checkIn' => $row->check_in_at?->toIso8601String(),
            'checkOut' => $row->check_out_at?->toIso8601String(),
            'checkInMethod' => $row->check_in_method,
            'checkOutMethod' => $row->check_out_method,
            // App paths (under /api), fetched with the signed-in token.
            'checkInPhoto' => $row->check_in_photo ? $photo('in') : null,
            'checkOutPhoto' => $row->check_out_photo ? $photo('out') : null,
            'distanceMeters' => $row->check_in_distance_m,
            'lateMinutes' => (int) $row->late_minutes,
            'workedMinutes' => $row->worked_minutes,
            'note' => $row->note,
            'markedByOwner' => $row->marked_by !== null,
        ];
    }

    /** Minutes past shift start, once past the grace; 0 when on time or no shift set. */
    private function lateMinutes(BusinessStaff $staff, string $date, Carbon $at, int $grace): int
    {
        if ($staff->shift_start === null) {
            return 0;
        }
        $zone = config('app.business_timezone', 'Asia/Kolkata');
        $start = Carbon::parse($date.' '.substr((string) $staff->shift_start, 0, 5), $zone);
        $late = (int) floor($start->diffInMinutes($at, false));

        return $late > $grace ? $late : 0;
    }

    /** Metres from the shop; refuses a punch clearly outside the radius. */
    private function checkDistance(Business $business, ?float $lat, ?float $lng, ?float $accuracy, int $radius): ?int
    {
        if ($radius <= 0 || $business->latitude === null || $business->longitude === null) {
            return null;
        }
        if ($lat === null || $lng === null) {
            throw ValidationException::withMessages(['location' => ['Turn on location to check in from your phone.']]);
        }

        $distance = (int) round(self::metres((float) $business->latitude, (float) $business->longitude, $lat, $lng));
        // Indoors GPS drifts: give the reported accuracy (up to 100 m) the benefit of the doubt.
        $slack = min(100.0, max(0.0, (float) $accuracy));
        if ($distance - $slack > $radius) {
            throw ValidationException::withMessages(['location' => [
                'You are about '.($distance >= 1000 ? round($distance / 1000, 1).' km' : $distance.' m').' from the shop. Check in when you reach the shop.',
            ]]);
        }

        return $distance;
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Stream a selfie (private storage) for the owner's screen. */
    public function photo(StaffAttendance $row, string $which): ?string
    {
        $path = $which === 'out' ? $row->check_out_photo : $row->check_in_photo;

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }
}
