<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastNotification;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Broadcast notifications (document/phase/14 §Notification Center). Queues an
 * in-app notification (+ best-effort push) to a target audience. The fan-out
 * runs on a queued job (chunked) so a large broadcast never blocks the request.
 */
class BroadcastController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** POST /admin/broadcast */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'target' => ['required', 'in:all,customers,owners'],
            'link' => ['nullable', 'string', 'max:255'],
        ]);

        $data = ! empty($validated['link']) ? ['link' => $validated['link']] : [];

        // Count the audience up front so the admin sees how many will be reached;
        // the actual delivery is handed to the queue.
        $recipients = SendBroadcastNotification::audienceQuery($validated['target'])->count();

        SendBroadcastNotification::dispatch(
            $validated['target'],
            $validated['title'],
            $validated['body'] ?? null,
            $data,
        );

        $this->audit->log(
            $request->user(),
            'broadcast.send',
            null,
            "Broadcast to {$validated['target']} ({$recipients} recipients)",
            ['target' => $validated['target'], 'sent' => $recipients, 'title' => $validated['title']],
        );

        return ApiResponse::success(
            ['sent' => $recipients],
            "Broadcast queued for {$recipients} recipients.",
        );
    }
}
