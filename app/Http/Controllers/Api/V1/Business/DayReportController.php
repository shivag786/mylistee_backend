<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\DayClosing;
use App\Services\DayReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Day-end report, cash in/out of the drawer, and closing the day. */
class DayReportController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly DayReportService $reports) {}

    /** GET /business/day-report?date=Y-m-d (default: the current business day) */
    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $business->businessDateOf();

        $report = $this->reports->report($business, $date);
        // A cashier with the drawer but not "see sales": only the cash part.
        if (! $request->user()->canAtShop('reports')) {
            $report = array_intersect_key($report, array_flip(['date', 'from', 'to', 'cash', 'closing', 'payments', 'openBills']));
        }

        return ApiResponse::success($report + ['today' => $business->businessDateOf()], 'Day report.');
    }

    /** POST /business/day-close {date?, countedCash, cashLeft?, note?} */
    public function close(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'countedCash' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'cashLeft' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $report = $this->reports->close(
            $business,
            $data['date'] ?? $business->businessDateOf(),
            (float) $data['countedCash'],
            isset($data['cashLeft']) ? (float) $data['cashLeft'] : null,
            $data['note'] ?? null,
            $request->user(),
        );

        return ApiResponse::success($report, 'Day closed.');
    }

    /** GET /business/day-close?month=Y-m — past closings. */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $start = Carbon::parse(($data['month'] ?? now()->format('Y-m')).'-01');

        $rows = DayClosing::where('business_id', $business->id)
            ->whereDate('business_date', '>=', $start->toDateString())
            ->whereDate('business_date', '<=', $start->copy()->endOfMonth()->toDateString())
            ->with('closer:id,name')->orderByDesc('business_date')->get();

        return ApiResponse::success([
            'closings' => $rows->map(fn ($c) => $this->reports->closingPayload($c))->values()->all(),
            'totalSales' => round((float) $rows->sum('total_sales'), 2),
            'totalDifference' => round((float) $rows->sum('difference'), 2),
        ], 'Day closings.');
    }

    /** POST /business/cash-movements {type: in|out, amount, reason} */
    public function storeMovement(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $movement = $this->reports->addMovement($business, $data['type'], (float) $data['amount'], $data['reason'], $request->user());

        return ApiResponse::success($this->reports->movementPayload($movement->load('user')), 'Saved.', status: 201);
    }

    /** DELETE /business/cash-movements/{uuid} — today's, before the day is closed. */
    public function destroyMovement(Request $request, string $uuid): JsonResponse
    {
        $business = $this->business($request);
        $movement = CashMovement::where('business_id', $business->id)->where('uuid', $uuid)->first();
        if ($movement === null) {
            return ApiResponse::error('Entry not found.', status: 404);
        }
        $closed = DayClosing::where('business_id', $business->id)->whereDate('business_date', $movement->business_date->toDateString())->exists();
        if ($closed || $movement->business_date->toDateString() !== $business->businessDateOf()) {
            return ApiResponse::error('Only today\'s entries can be removed, before the day is closed.', status: 422);
        }
        // Staff may remove only what they entered.
        if ($request->user()->isStaff() && $movement->user_id !== $request->user()->id) {
            return ApiResponse::error('Only the person who added it (or the owner) can remove it.', status: 403);
        }
        $movement->delete();

        return ApiResponse::success(null, 'Removed.');
    }
}
