<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PersonalLinkGrant;
use App\Services\AuditService;
use App\Services\PersonalLinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Admin side of personal links: see every shop's status, give a package free
 * (or record one paid offline), or send a Razorpay payment link for it.
 */
class PersonalLinkController extends Controller
{
    public function __construct(
        private readonly PersonalLinkService $links,
        private readonly AuditService $audit,
    ) {}

    /** GET /admin/personal-links?search=&status=active|expiring|expired|never */
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $status = $request->string('status')->trim()->value();

        $page = Business::query()
            ->select(['id', 'uuid', 'name', 'slug', 'owner_name', 'phone', 'email', 'personal_link_enabled', 'personal_link_expires_at', 'personal_link_trial_used_at'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('owner_name', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($q) => $q->where('personal_link_enabled', true)->where('personal_link_expires_at', '>', now()))
            ->when($status === 'expiring', fn ($q) => $q->where('personal_link_enabled', true)
                ->whereBetween('personal_link_expires_at', [now(), now()->addDays(7)]))
            ->when($status === 'expired', fn ($q) => $q->whereNotNull('personal_link_expires_at')->where('personal_link_expires_at', '<=', now()))
            ->when($status === 'never', fn ($q) => $q->whereNull('personal_link_trial_used_at'))
            ->withCount(['personalLinkGrants as pending_links' => fn ($q) => $q->where('status', 'pending')])
            // Soonest expiry first; shops that never used it last.
            ->orderByRaw('personal_link_expires_at IS NULL, personal_link_expires_at ASC')
            ->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn (Business $b) => [
                'id' => $b->uuid,
                'name' => $b->name,
                'ownerName' => $b->owner_name,
                'phone' => $b->phone,
                'email' => $b->email,
                'enabled' => (bool) $b->personal_link_enabled,
                'active' => $b->hasActivePersonalLink(),
                'expiresAt' => $b->personal_link_expires_at?->toIso8601String(),
                'daysLeft' => $b->personal_link_expires_at?->isFuture()
                    ? (int) ceil(now()->diffInSeconds($b->personal_link_expires_at) / 86400) : 0,
                'trialUsed' => $b->personal_link_trial_used_at !== null,
                'pendingLinks' => (int) $b->pending_links,
            ])->values(),
            'Personal links retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** GET /admin/personal-links/{uuid} — one shop's state + full history. */
    public function show(string $uuid): JsonResponse
    {
        $business = $this->business($uuid);
        $this->links->syncPending($business);
        $business->refresh();

        return ApiResponse::success($this->detail($business), 'Personal link retrieved.');
    }

    /**
     * POST /admin/personal-links/{uuid}/grant
     * { pack: month|quarter|year|custom, days?, mode: free|link, amount?, note?, notify? }
     */
    public function grant(Request $request, string $uuid): JsonResponse
    {
        $business = $this->business($uuid);

        $v = $request->validate([
            'pack' => ['required', Rule::in([...array_keys(PersonalLinkService::PACKS), 'custom'])],
            'days' => ['required_if:pack,custom', 'nullable', 'integer', 'min:1', 'max:3650'],
            'mode' => ['required', Rule::in(['free', 'link'])],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $days = isset($v['days']) ? (int) $v['days'] : null;

        try {
            if ($v['mode'] === 'free') {
                $grant = $this->links->grantFree($business, $v['pack'], $days, (float) ($v['amount'] ?? 0), $v['note'] ?? null, $request->user());
                $message = "{$grant->days} days added — new expiry {$grant->expires_after?->timezone(config('app.business_timezone'))->format('d M Y')}.";
            } else {
                $grant = $this->links->createPaymentLink(
                    $business, $v['pack'], $days,
                    isset($v['amount']) ? (float) $v['amount'] : null,
                    $v['note'] ?? null, (bool) ($v['notify'] ?? true), $request->user(),
                );
                $message = 'Payment link created. Days are added as soon as it is paid.';
            }
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        $this->audit->log($request->user(), "personal_link.{$v['mode']}", $business,
            "Personal link {$grant->days} days ({$v['mode']}) for {$business->name}", ['grant' => $grant->uuid, 'amount' => (float) $grant->amount]);

        return ApiResponse::success(
            ['grant' => $grant->toApi()] + $this->detail($business->refresh()),
            $message,
        );
    }

    /** POST /admin/personal-links/grants/{uuid}/sync — "Check payment" on a pending link. */
    public function sync(string $uuid): JsonResponse
    {
        $grant = PersonalLinkGrant::where('uuid', $uuid)->firstOrFail();

        try {
            $grant = $this->links->syncLink($grant);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        $message = match ($grant->status) {
            'active' => 'Paid — days added.',
            'cancelled' => 'This link was cancelled or has expired.',
            default => 'Not paid yet.',
        };

        return ApiResponse::success($this->detail($grant->business->refresh()), $message);
    }

    /** POST /admin/personal-links/grants/{uuid}/cancel — withdraw an unpaid link. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $grant = PersonalLinkGrant::where('uuid', $uuid)->firstOrFail();

        try {
            $grant = $this->links->cancelLink($grant);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 503);
        }

        $this->audit->log($request->user(), 'personal_link.cancel_link', $grant->business, 'Cancelled a personal link payment link');

        return ApiResponse::success(
            $this->detail($grant->business->refresh()),
            $grant->status === 'active' ? 'It was already paid — days were added.' : 'Payment link cancelled.',
        );
    }

    private function business(string $uuid): Business
    {
        return Business::where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function detail(Business $business): array
    {
        $state = $this->links->state($business);
        $state['history'] = $business->personalLinkGrants()->with('grantedBy:id,name')->latest('id')->get()->map->toApi()->all();

        return [
            'business' => ['id' => $business->uuid, 'name' => $business->name, 'phone' => $business->phone, 'email' => $business->email],
            'state' => $state,
        ];
    }
}
