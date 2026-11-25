<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastNotification;
use App\Models\AdminBroadcast;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Services\AuditService;
use App\Services\FirebaseService;
use App\Services\ShopPushService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin push broadcasts (document/phase/14 §Notification Center): everyone,
 * all customers, all shop owners (or one category's), one shop's customers,
 * or a test to the admin's own phone. Each recipient gets an in-app
 * notification plus a push on every device; the fan-out runs after the
 * response and its result is kept for the history list.
 */
class BroadcastController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly FirebaseService $firebase,
    ) {}

    /** GET /admin/broadcast — the last sends and whether push is set up. */
    public function index(): JsonResponse
    {
        $items = AdminBroadcast::with(['business:id,name', 'category:id,name'])
            ->latest('id')->limit(25)->get()
            ->map(fn (AdminBroadcast $b) => [
                'id' => $b->uuid,
                'title' => $b->title,
                'body' => $b->body,
                'link' => $b->link,
                'target' => $b->target,
                'audienceLabel' => $this->label($b),
                'recipients' => $b->recipients,
                'reachable' => $b->reachable,
                'pushed' => $b->pushed,
                'failed' => $b->failed,
                'status' => $b->status,
                'createdAt' => $b->created_at?->toIso8601String(),
                'sentAt' => $b->sent_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'pushConfigured' => $this->firebase->isConfigured(),
            'shopAudiences' => collect(ShopPushService::AUDIENCES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'items' => $items,
        ], 'Broadcasts retrieved.');
    }

    /** GET /admin/broadcast/preview — how many people, and how many on a phone. */
    public function preview(Request $request): JsonResponse
    {
        $opts = $this->options($this->validated($request, preview: true), $request);
        $query = SendBroadcastNotification::audienceQuery($opts['target'], $opts);

        return ApiResponse::success([
            'recipients' => (clone $query)->count(),
            'reachable' => (clone $query)->whereHas('deviceTokens')->count(),
        ], 'Audience counted.');
    }

    /** POST /admin/broadcast */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $opts = $this->options($validated, $request);
        $query = SendBroadcastNotification::audienceQuery($opts['target'], $opts);

        $recipients = (clone $query)->count();
        if ($recipients === 0) {
            return ApiResponse::error('Nobody in this audience yet.', status: 422);
        }

        $broadcast = AdminBroadcast::create([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'link' => $validated['link'] ?? null,
            'target' => $opts['target'],
            'business_id' => $opts['businessId'],
            'audience' => $opts['target'] === 'shop_customers' ? $opts['audience'] : null,
            'category_id' => $opts['categoryId'],
            'recipients' => $recipients,
            'reachable' => (clone $query)->whereHas('deviceTokens')->count(),
            'created_by' => $request->user()->id,
        ]);

        // After the response, in this process — the server has no queue worker,
        // so a queued broadcast would never go out.
        SendBroadcastNotification::dispatchAfterResponse($broadcast->id);

        $this->audit->log(
            $request->user(),
            'broadcast.send',
            null,
            "Broadcast to {$this->label($broadcast)} ({$recipients} recipients)",
            ['target' => $opts['target'], 'sent' => $recipients, 'title' => $validated['title']],
        );

        return ApiResponse::success(
            ['id' => $broadcast->uuid, 'sent' => $recipients, 'reachable' => $broadcast->reachable],
            "Broadcast sending to {$recipients} recipients.",
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $preview = false): array
    {
        return $request->validate([
            'title' => [$preview ? 'nullable' : 'required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'target' => ['required', Rule::in(['all', 'customers', 'owners', 'shop_customers', 'test'])],
            'link' => ['nullable', 'string', 'max:255'],
            'businessId' => ['required_if:target,shop_customers', 'nullable', 'string'],
            'audience' => ['nullable', Rule::in(array_keys(ShopPushService::AUDIENCES))],
            'categoryId' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{target: string, businessId: ?int, audience: string, categoryId: ?int, userId: int}
     */
    private function options(array $v, Request $request): array
    {
        $businessId = ! empty($v['businessId']) ? Business::where('uuid', $v['businessId'])->value('id') : null;
        if ($v['target'] === 'shop_customers' && $businessId === null) {
            abort(ApiResponse::error('Shop not found.', status: 422));
        }

        return [
            'target' => $v['target'],
            'businessId' => $v['target'] === 'shop_customers' ? $businessId : null,
            'audience' => $v['audience'] ?? 'everyone',
            'categoryId' => $v['target'] === 'owners' && ! empty($v['categoryId'])
                ? BusinessCategory::where('uuid', $v['categoryId'])->value('id')
                : null,
            'userId' => $request->user()->id,
        ];
    }

    private function label(AdminBroadcast $b): string
    {
        return match ($b->target) {
            'all' => 'Everyone',
            'customers' => 'All customers',
            'owners' => $b->category ? "Shop owners · {$b->category->name}" : 'All shop owners',
            'shop_customers' => ($b->business->name ?? 'A shop').' · '.(ShopPushService::AUDIENCES[$b->audience] ?? 'customers'),
            'test' => 'Test to me',
            default => $b->target,
        };
    }
}
