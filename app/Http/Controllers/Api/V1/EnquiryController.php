<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Website enquiries: anyone can send one (no account); admins see and follow
 * them up. Every admin gets an in-app notification + push for a new one.
 */
class EnquiryController extends Controller
{
    public const TOPICS = ['shop', 'partner', 'support', 'other'];

    public const STATUSES = ['new', 'contacted', 'converted', 'closed'];

    /** POST /enquiries (public, throttled) */
    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        // Honeypot: a hidden field people never see; bots fill it in.
        if (filled($request->input('website'))) {
            return ApiResponse::success(null, 'Thanks! We will call you soon.');
        }

        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s]{10,20}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'businessName' => ['nullable', 'string', 'max:120'],
            'businessType' => ['nullable', 'string', 'max:60'],
            'topic' => ['nullable', Rule::in(self::TOPICS)],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:255'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        $enquiry = Enquiry::create([
            'name' => $v['name'],
            'phone' => $v['phone'],
            'email' => $v['email'] ?? null,
            'city' => $v['city'] ?? null,
            'business_name' => $v['businessName'] ?? null,
            'business_type' => $v['businessType'] ?? null,
            'topic' => $v['topic'] ?? 'shop',
            'message' => $v['message'] ?? null,
            'source' => $v['source'] ?? null,
            'ip' => $request->ip(),
        ]);

        // Tell the team. Never fail the visitor's request over a push.
        try {
            $where = $enquiry->city ? " from {$enquiry->city}" : '';
            User::query()->where('role', UserRole::Admin->value)->where('status', 'active')->get()
                ->each(fn (User $admin) => $notifications->notify(
                    $admin,
                    NotificationType::System,
                    "New enquiry{$where}: {$enquiry->name}",
                    trim(($enquiry->business_name ? "{$enquiry->business_name} · " : '').$enquiry->phone.' · '.str($enquiry->message ?? '')->limit(80)),
                    ['link' => '/admin/enquiries'],
                ));
        } catch (\Throwable $e) {
            Log::warning('Enquiry notify failed', ['error' => $e->getMessage()]);
        }

        return ApiResponse::success(['id' => $enquiry->uuid], 'Thanks! Our team will call you within one working day.', 201);
    }

    /** GET /admin/enquiries?status=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in([...self::STATUSES, 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $v['status'] ?? 'all';
        $like = isset($v['search']) && $v['search'] !== '' ? '%'.addcslashes($v['search'], '%_\\').'%' : null;

        $page = Enquiry::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like)->orWhere('business_name', 'like', $like)))
            ->latest('id')
            ->paginate(25, page: $v['page'] ?? 1);

        $counts = Enquiry::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return ApiResponse::success([
            'counts' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'items' => collect($page->items())->map(fn (Enquiry $e) => $this->row($e))->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ], 'Enquiries retrieved.');
    }

    /** PATCH /admin/enquiries/{uuid} — status and the team's notes. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $enquiry = Enquiry::where('uuid', $uuid)->firstOrFail();
        $enquiry->update($v);

        return ApiResponse::success($this->row($enquiry), 'Enquiry updated.');
    }

    /** @return array<string, mixed> */
    private function row(Enquiry $e): array
    {
        return [
            'id' => $e->uuid,
            'name' => $e->name,
            'phone' => $e->phone,
            'email' => $e->email,
            'city' => $e->city,
            'businessName' => $e->business_name,
            'businessType' => $e->business_type,
            'topic' => $e->topic,
            'message' => $e->message,
            'source' => $e->source,
            'status' => $e->status,
            'notes' => $e->notes,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }
}
