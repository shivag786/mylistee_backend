<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Broadcast notifications (document/phase/14 §Notification Center). Sends an
 * in-app notification (+ best-effort push) to a target audience. Chunked so a
 * large audience doesn't exhaust memory.
 */
class BroadcastController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
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
        $sent = 0;

        $this->audienceQuery($validated['target'])->chunkById(200, function ($users) use ($validated, $data, &$sent): void {
            foreach ($users as $user) {
                $this->notifications->notify(
                    $user,
                    NotificationType::System,
                    $validated['title'],
                    $validated['body'] ?? null,
                    $data,
                );
                $sent++;
            }
        });

        $this->audit->log(
            $request->user(),
            'broadcast.send',
            null,
            "Broadcast to {$validated['target']} ({$sent} recipients)",
            ['target' => $validated['target'], 'sent' => $sent, 'title' => $validated['title']],
        );

        return ApiResponse::success(['sent' => $sent], "Broadcast sent to {$sent} recipients.");
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function audienceQuery(string $target)
    {
        return User::query()
            ->where('status', 'active')
            ->when($target === 'customers', fn ($q) => $q->where('role', UserRole::Customer->value))
            ->when($target === 'owners', fn ($q) => $q->where('role', UserRole::BusinessOwner->value));
    }
}
