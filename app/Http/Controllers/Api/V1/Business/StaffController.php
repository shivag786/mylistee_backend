<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\BusinessStaff;
use App\Rules\StrongPin;
use App\Services\StaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner's staff list. Each person gets a PIN and the shop code; they sign
 * in to the same vendor app and see only what the owner ticked.
 */
class StaffController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly StaffService $staff) {}

    /** GET /business/staff */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $list = $business->staff()->with('user:id,last_login_at')->orderByDesc('active')->orderBy('name')->get();
        $isOwner = ! $request->user()->isStaff();

        return ApiResponse::success([
            // The code staff type to sign in — the owner's to hand out.
            'shopCode' => $isOwner ? $business->staffCode() : null,
            'staff' => $list->map(fn (BusinessStaff $s) => $isOwner
                ? $this->staff->payload($s)
                : ['id' => $s->uuid, 'name' => $s->name, 'role' => $s->role, 'active' => (bool) $s->active])->values()->all(),
            'roles' => collect(StaffService::roles())->map(fn ($r, $id) => ['id' => $id, 'name' => $r['name'], 'permissions' => $r['permissions'], 'maxDiscount' => $r['maxDiscount']])->values()->all(),
            'permissions' => collect(StaffService::permissionList())->map(fn ($p, $id) => ['id' => $id, 'name' => $p['name'], 'description' => $p['description']])->values()->all(),
        ], 'Staff.');
    }

    /** POST /business/staff */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate($this->rules(true), $this->messages());

        $staff = $this->staff->create($business, $data);

        return ApiResponse::success(
            $this->staff->payload($staff->load('user')) + ['shopCode' => $business->staffCode()],
            "{$staff->name} added. They sign in with shop code {$business->staffCode()}, their mobile and PIN.",
            status: 201,
        );
    }

    /** PUT /business/staff/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $staff = $this->find($request, $uuid);
        if ($staff === null) {
            return ApiResponse::error('Staff member not found.', status: 404);
        }
        $data = $request->validate($this->rules(false), $this->messages());

        return ApiResponse::success($this->staff->payload($this->staff->update($staff, $data)->load('user')), 'Saved.');
    }

    /** DELETE /business/staff/{uuid} — removes access; past records stay. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $staff = $this->find($request, $uuid);
        if ($staff === null) {
            return ApiResponse::error('Staff member not found.', status: 404);
        }
        $this->staff->remove($staff);

        return ApiResponse::success(null, "{$staff->name} removed and signed out.");
    }

    private function find(Request $request, string $uuid): ?BusinessStaff
    {
        return $this->business($request)->staff()->where('uuid', $uuid)->first();
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:80'],
            'phone' => [$req, 'string', 'regex:/^(\+?91)?[6-9]\d{9}$/'],
            'pin' => [$creating ? 'required' : 'nullable', 'string', 'min:4', 'max:6', 'regex:/^[0-9]+$/', new StrongPin],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(StaffService::roles()))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(StaffService::permissionList()))],
            'maxDiscountPercent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'shiftStart' => ['sometimes', 'nullable', 'date_format:H:i'],
            'shiftEnd' => ['sometimes', 'nullable', 'date_format:H:i'],
            'weekOff' => ['sometimes', 'nullable', 'array', 'max:6'],
            'weekOff.*' => ['integer', 'between:1,7'],
            'monthlySalary' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'joinedOn' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit mobile number.',
            'pin.regex' => 'PIN is 4 to 6 digits.',
        ];
    }
}
