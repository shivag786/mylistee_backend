<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCustomerResource;
use App\Models\User;
use App\Rules\StrongPin;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
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
                        ->orWhere('email', 'like', "%{$search}%")
                        // A customer locked out of a mobile account calls in with
                        // their number -- the admin has to be able to find them by it.
                        ->orWhere('phone', 'like', "%{$search}%");
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

    /**
     * POST /admin/customers/{uuid}/reset-pin -- for a customer who forgot their
     * PIN, or whose number someone else signed up with.
     *
     * With no OTP and no email, this is the only way back in, and the admin is
     * the check: call the number, confirm it is them, then read them the new
     * PIN. It is shown in this response only -- never stored readable, never in
     * the audit log -- and every existing session is signed out, so whoever held
     * the account before the reset loses it.
     */
    public function resetPin(Request $request, string $uuid): JsonResponse
    {
        $customer = User::where('uuid', $uuid)->where('role', UserRole::Customer->value)->first();
        if ($customer === null) {
            return ApiResponse::error('Customer not found.', status: 404);
        }

        // A Google-only customer has no number to sign in with, so a PIN would
        // open nothing.
        if (blank($customer->phone)) {
            return ApiResponse::error('This customer has no mobile number, so a PIN cannot be used to sign in.', status: 422);
        }

        $pin = $this->freshPin();

        $customer->forceFill(['pin' => $pin, 'pin_plain' => null])->save();
        $customer->tokens()->delete();

        // Lift any lockout their failed guesses left, so the new PIN works now.
        RateLimiter::clear('pin-login:'.mb_strtolower(trim($customer->phone)));

        $this->audit->log($request->user(), 'customer.pin_reset', $customer, 'Reset PIN and signed out every session');

        return ApiResponse::success(
            ['pin' => $pin, 'phone' => $customer->phone],
            'PIN reset. Share it with the customer -- it will not be shown again.',
        );
    }

    /** A random six-digit PIN that clears the same bar a customer's own must. */
    private function freshPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Validator::make(['pin' => $pin], ['pin' => [new StrongPin()]])->fails());

        return $pin;
    }
}
