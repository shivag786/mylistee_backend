<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminRevenueResource;
use App\Models\Subscription;
use App\Services\AdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Revenue page — which businesses bought which plan, with revenue KPIs.
 * One row per subscription (including cancelled/expired) so churn is visible.
 */
class RevenueController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    /** GET /admin/revenue */
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()
            ->with(['business:id,name,slug', 'plan:id,name'])
            ->withSum(
                ['invoices as total_paid' => fn ($q) => $q->where('status', InvoiceStatus::Paid->value)],
                'amount',
            )
            ->when($request->string('status')->trim()->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('plan')->trim()->value(), function ($q, $key): void {
                $q->whereHas('plan', fn ($p) => $p->where('key', $key));
            })
            ->when($request->string('search')->trim()->value(), function ($q, $search): void {
                $q->whereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });

        match ($request->string('sort')->value() ?: 'newest') {
            'revenue' => $query->orderByDesc('total_paid'),
            'price' => $query->orderByDesc('price'),
            default => $query->latest('id'),
        };

        $page = $query->paginate((int) $request->integer('perPage', 20));

        return ApiResponse::success(
            [
                'summary' => $this->admin->revenueSummary(),
                'rows' => AdminRevenueResource::collection($page->getCollection()),
                'meta' => [
                    'currentPage' => $page->currentPage(),
                    'lastPage' => $page->lastPage(),
                    'perPage' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
            'Revenue retrieved.',
        );
    }
}
