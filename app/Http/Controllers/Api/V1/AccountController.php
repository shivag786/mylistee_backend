<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Delete my account — the in-app path Google Play asks for, and what the
 * public "Delete account" page points to.
 *
 * Personal details are wiped at once and the account is closed (signed out
 * everywhere, cannot sign in again). Orders, invoices and payments stay, with
 * the name removed, because tax law makes shops and Listee keep bills; a
 * shop's own records (its menu, its orders) are hidden with the shop.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** DELETE /account   body: { confirm: "DELETE" } */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', 'in:DELETE,delete'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], ['confirm.in' => 'Type DELETE to confirm.']);

        /** @var User $user */
        $user = $request->user();

        if ($user->role === UserRole::Admin) {
            return ApiResponse::error('An admin account cannot be deleted from the app.', status: 403);
        }
        if ($user->role === UserRole::Staff) {
            return ApiResponse::error('Staff logins belong to the shop. Ask the owner to remove you.', status: 403);
        }

        DB::transaction(function () use ($user, $request): void {
            // A shop goes offline with its owner: off the map, no new orders,
            // its staff signed out.
            if ($user->role === UserRole::BusinessOwner) {
                foreach ($user->businesses()->get() as $business) {
                    $business->forceFill(['status' => BusinessStatus::Suspended->value])->save();
                    foreach ($business->staff()->with('user')->get() as $staff) {
                        $staff->forceFill(['active' => false])->save();
                        $staff->user?->tokens()->delete();
                    }
                }
            }

            $this->audit->log($user, 'account.deleted', $user, trim('Account deleted by its owner. '.($request->input('reason') ?? '')));

            $user->deviceTokens()->delete();
            $user->people()->delete();
            $user->tokens()->delete();

            // Wipe what identifies the person; keep the row for bills that point at it.
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => null,
                'phone' => null,
                'firebase_uid' => null,
                'avatar_url' => null,
                'dob' => null,
                'gender' => null,
                'anniversary' => null,
                'pin' => null,
                'pin_plain' => null,
                'password' => null,
                'status' => UserStatus::Blocked->value,
            ])->save();
            $user->delete();
        });

        return ApiResponse::success(null, 'Your account has been deleted.');
    }
}
