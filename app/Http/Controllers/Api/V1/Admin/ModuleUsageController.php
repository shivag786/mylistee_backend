<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ModuleUsage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What shops owe for per-use modules in a month (plans whose module entry has
 * a `fee`: GST billing per bill, staff / attendance per staff member a month).
 */
class ModuleUsageController extends Controller
{
    /** GET /admin/module-usage?month=Y-m */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $period = $data['month'] ?? now(config('app.business_timezone'))->format('Y-m');

        $rows = ModuleUsage::where('period', $period)
            ->selectRaw('business_id, module, unit, COUNT(*) as units, SUM(rate) as amount')
            ->groupBy('business_id', 'module', 'unit')
            ->get();
        $shops = Business::withTrashed()->whereIn('id', $rows->pluck('business_id')->unique())->get(['id', 'uuid', 'name'])->keyBy('id');

        return ApiResponse::success([
            'month' => $period,
            'total' => round((float) $rows->sum('amount'), 2),
            'shops' => $rows->groupBy('business_id')->map(fn ($g, $id) => [
                'id' => $shops[$id]->uuid ?? null,
                'name' => $shops[$id]->name ?? 'Deleted shop',
                'amount' => round((float) $g->sum('amount'), 2),
                'modules' => $g->map(fn ($r) => [
                    'module' => $r->module,
                    'name' => (string) (config("plan_modules.{$r->module}.name") ?? $r->module),
                    'unit' => $r->unit,
                    'units' => (int) $r->units,
                    'amount' => round((float) $r->amount, 2),
                ])->values()->all(),
            ])->sortByDesc('amount')->values()->all(),
        ], 'Module usage.');
    }
}
