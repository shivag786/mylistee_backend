<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only audit trail (document/phase/14 §Audit Logs). Immutable — this
 * endpoint only ever reads.
 */
class AuditLogController extends Controller
{
    /** GET /admin/audit-logs */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with('user:id,name')
            ->when($request->string('action')->trim()->value(), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->latest('id');

        $page = $query->paginate((int) $request->integer('perPage', 30));

        return ApiResponse::success(
            AuditLogResource::collection($page->getCollection()),
            'Audit logs retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
