<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\BusinessPosSetting;
use App\Models\StaffAttendance;
use App\Services\AttendanceService;
use App\Services\StaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attendance: staff punch on their phone (selfie + GPS) or on the shop's
 * tablet (name + PIN + selfie); the owner sees the day, the month and the
 * selfies, corrects a day, and sets the rules.
 */
class AttendanceController extends Controller
{
    use ResolvesBusiness;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly StaffService $staff,
    ) {}

    /** POST /business/attendance/punch — a staff member, on their own phone. */
    public function punch(Request $request): JsonResponse
    {
        $staff = $request->user()->staffProfile;
        if ($staff === null) {
            return ApiResponse::error('Only staff check in. The owner\'s day isn\'t tracked.', status: 422);
        }
        $data = $request->validate($this->punchRules());

        $result = $this->attendance->punch(
            $staff,
            'self',
            $request->file('photo'),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
        );

        return $this->punched($result, $staff->name);
    }

    /** POST /business/attendance/kiosk — the shop tablet: {staff, pin, photo}. */
    public function kiosk(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'staff' => ['required', 'string'],
            'pin' => ['required', 'string', 'max:8'],
        ] + $this->punchRules());

        $staff = $business->staff()->where('uuid', $data['staff'])->where('active', true)->first();
        if ($staff === null) {
            return ApiResponse::error('Staff member not found.', status: 404);
        }
        $this->staff->checkPin($staff, $data['pin']);

        return $this->punched($this->attendance->punch($staff, 'kiosk', $request->file('photo'), null, null, null), $staff->name);
    }

    /** GET /business/attendance?date= — everyone's day. */
    public function day(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $business->businessDateOf();

        return ApiResponse::success([
            'date' => $date,
            'today' => $business->businessDateOf(),
            'rows' => $this->attendance->day($business, $date),
            'settings' => $business->posSettings()->attendance(),
            'kioskStaff' => $business->staff()->where('active', true)->orderBy('name')->get(['uuid', 'name', 'role'])
                ->map(fn ($s) => ['id' => $s->uuid, 'name' => $s->name, 'role' => $s->role])->values()->all(),
        ], 'Attendance.');
    }

    /** GET /business/attendance/month?month=Y-m — per person, with salary estimate. */
    public function month(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now(config('app.business_timezone'))->format('Y-m');
        $rows = $this->attendance->month($business, $month);
        // Salaries are the owner's business only.
        if ($request->user()->isStaff()) {
            $rows = array_map(fn ($r) => array_diff_key($r, ['salaryEstimate' => 1]) + ['staff' => array_diff_key($r['staff'], ['monthlySalary' => 1])], $rows);
        }

        return ApiResponse::success(['month' => $month, 'staff' => $rows], 'Attendance for the month.');
    }

    /** GET /business/attendance/me?month= — a staff member's own month and today. */
    public function me(Request $request): JsonResponse
    {
        $staff = $request->user()->staffProfile;
        if ($staff === null) {
            return ApiResponse::error('Only for staff.', status: 422);
        }
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $business = $staff->business;
        $month = $data['month'] ?? now(config('app.business_timezone'))->format('Y-m');
        $today = $staff->attendances()->whereDate('date', $business->businessDateOf())->first()
            ?? $staff->attendances()->whereNotNull('check_in_at')->whereNull('check_out_at')->where('check_in_at', '>=', now()->subHours(20))->first();
        $summary = $this->attendance->month($business, $month, $staff)[0] ?? null;

        return ApiResponse::success([
            'today' => $today ? $this->attendance->payload($today) : null,
            'settings' => array_intersect_key($business->posSettings()->attendance(), array_flip(['selfPunch', 'requirePhoto', 'radiusMeters'])),
            'shiftStart' => $staff->shift_start ? substr((string) $staff->shift_start, 0, 5) : null,
            'shiftEnd' => $staff->shift_end ? substr((string) $staff->shift_end, 0, 5) : null,
            'month' => $month,
            'summary' => $summary ? array_diff_key($summary, ['salaryEstimate' => 1]) : null,
        ], 'Your attendance.');
    }

    /** PUT /business/attendance/{staff}/{date} — correct or mark a day by hand. */
    public function mark(Request $request, string $staffUuid, string $date): JsonResponse
    {
        $business = $this->business($request);
        $staff = $business->staff()->withTrashed()->where('uuid', $staffUuid)->first();
        if ($staff === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ApiResponse::error('Not found.', status: 404);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'checkIn' => ['nullable', 'date_format:H:i'],
            'checkOut' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        if ($date > $business->businessDateOf() && ! in_array($data['status'], ['leave', 'week_off'], true)) {
            return ApiResponse::error('Only leave or a week off can be marked ahead.', status: 422);
        }

        $row = $this->attendance->mark($staff, $date, $data, $request->user());

        return ApiResponse::success($this->attendance->payload($row), 'Saved.');
    }

    /** GET /business/attendance/photo/{uuid}/{which} — a check-in / check-out selfie. */
    public function photo(Request $request, string $uuid, string $which): Response
    {
        $business = $this->business($request);
        $row = StaffAttendance::where('business_id', $business->id)->where('uuid', $uuid)->first();
        $user = $request->user();
        // The owner and attendance managers see all; a staff member their own.
        $mine = $row !== null && $user->staffProfile?->id === $row->staff_id;
        if ($row === null || (! $mine && ! $user->canAtShop('staff'))) {
            return ApiResponse::error('Not found.', status: 404);
        }
        $path = $this->attendance->photo($row, $which);
        if ($path === null) {
            return ApiResponse::error('No photo.', status: 404);
        }

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** GET /business/attendance/settings */
    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->business($request)->posSettings()->attendance(), 'Attendance settings.');
    }

    /** PUT /business/attendance/settings */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($request->user()->isStaff()) {
            return ApiResponse::error('Only the owner can change attendance rules.', status: 403);
        }
        $business = $this->business($request);
        $data = $request->validate([
            'selfPunch' => ['sometimes', 'boolean'],
            'kiosk' => ['sometimes', 'boolean'],
            'requirePhoto' => ['sometimes', 'boolean'],
            'radiusMeters' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'graceMinutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'halfDayHours' => ['sometimes', 'numeric', 'min:0', 'max:12'],
        ]);
        $settings = $business->posSettings();
        $settings->update(['attendance_settings' => array_merge($settings->attendance(), $data)]);

        return ApiResponse::success($settings->fresh()->attendance(), 'Attendance settings saved.');
    }

    /** @return array<string, mixed> */
    private function punchRules(): array
    {
        return [
            'photo' => ['nullable', 'image', 'max:6144'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @param  array{action: string, attendance: StaffAttendance}  $result */
    private function punched(array $result, string $name): JsonResponse
    {
        $row = $result['attendance'];
        $message = $result['action'] === 'in'
            ? ($row->late_minutes > 0 ? "{$name} checked in, {$row->late_minutes} min late." : "{$name} checked in. Have a good shift!")
            : "{$name} checked out after ".intdiv((int) $row->worked_minutes, 60).'h '.((int) $row->worked_minutes % 60).'m.';

        return ApiResponse::success(['action' => $result['action']] + $this->attendance->payload($row), $message);
    }
}
