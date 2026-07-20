<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCustomerResource;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer management for the Super Admin (document/phase/14 §Customer
 * Management). Wallets are never edited directly — only account status changes.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** GET /admin/customers */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('role', UserRole::Customer->value)
            ->withCount(['spins', 'rewards'])
            ->when($request->string('search')->trim()->value(), function ($q, $search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            AdminCustomerResource::collection($page->getCollection()),
            'Customers retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** GET /admin/customers/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $customer = User::withCount(['spins', 'rewards'])
            ->where('uuid', $uuid)->where('role', UserRole::Customer->value)->first();

        if ($customer === null) {
            return ApiResponse::error('Customer not found.', status: 404);
        }

        return ApiResponse::success(new AdminCustomerResource($customer), 'Customer retrieved.');
    }

    /** PATCH /admin/customers/{uuid}/status — active / suspended / blocked. */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                UserStatus::Active->value,
                UserStatus::Suspended->value,
                UserStatus::Blocked->value,
            ])],
        ]);

        $customer = User::where('uuid', $uuid)->where('role', UserRole::Customer->value)->first();
        if ($customer === null) {
            return ApiResponse::error('Customer not found.', status: 404);
        }

        $customer->update(['status' => $validated['status']]);

        // A suspended/blocked account's tokens should stop working immediately.
        if ($validated['status'] !== UserStatus::Active->value) {
            $customer->tokens()->delete();
        }

        $this->audit->log(
            $request->user(),
            'customer.status',
            $customer,
            "Set status to {$validated['status']}",
            ['status' => $validated['status']],
        );

        return ApiResponse::success(
            new AdminCustomerResource($customer->loadCount(['spins', 'rewards'])),
            'Customer updated.',
        );
    }
}
