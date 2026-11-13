<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ModuleUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Billable use of modules a plan prices per use instead of (or on top of)
 * its price: the admin sets a `fee` on the plan's module entry, charged per
 * the module's `feeUnit` (config/plan_modules.php):
 *
 *  - billing: per paid bill            (unit "order", reference = order uuid)
 *  - staff / attendance: per active staff member a month (unit "staff_month")
 *
 * Each unit is recorded once (unique per shop, module, month and reference),
 * with the rate at the time. What is owed for a month is the sum — shown to
 * the admin (and the owner) and settled with the subscription invoice.
 */
class ModuleUsageService
{
    public function __construct(private readonly PlanAccessService $access) {}

    public function record(Business $business, string $module, string $reference): void
    {
        $plan = $this->access->snapshot($business)['plan'] ?? null;
        $fee = $plan?->moduleConfig($module)['fee'] ?? null;
        if ($fee === null) {
            return;
        }

        try {
            ModuleUsage::firstOrCreate([
                'business_id' => $business->id,
                'module' => $module,
                'period' => Carbon::now(config('app.business_timezone', 'Asia/Kolkata'))->format('Y-m'),
                'reference' => $reference,
            ], [
                'unit' => (string) (config("plan_modules.{$module}.feeUnit") ?? 'order'),
                'rate' => $fee,
            ]);
        } catch (QueryException) {
            // Counted already by a concurrent request.
        }
    }

    /**
     * What a shop's per-use modules add up to for a month.
     *
     * @return list<array{module: string, name: string, unit: string, units: int, amount: float}>
     */
    public function monthFor(Business $business, string $period): array
    {
        return ModuleUsage::query()
            ->where('business_id', $business->id)
            ->where('period', $period)
            ->selectRaw('module, unit, COUNT(*) as units, SUM(rate) as amount')
            ->groupBy('module', 'unit')
            ->get()
            ->map(fn ($row) => [
                'module' => $row->module,
                'name' => (string) (config("plan_modules.{$row->module}.name") ?? $row->module),
                'unit' => $row->unit,
                'units' => (int) $row->units,
                'amount' => round((float) $row->amount, 2),
            ])->values()->all();
    }
}
