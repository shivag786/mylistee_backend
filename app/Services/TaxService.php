<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GST on a bill, and the bill's invoice number.
 *
 * A restaurant's supply is within its state, so GST is split half CGST, half
 * SGST. Each line carries its own rate (the product's, else the shop's
 * default, usually 5%). Discounts — the shop's, a coupon, the counter's, coins
 * — come off before tax, spread over the lines by value. The service charge is
 * a percent of the discounted food value and is taxed with it; a delivery fee
 * is taxed at the default rate.
 *
 * Prices may be tax-exclusive (GST added on the bill — the usual dine-in way)
 * or tax-inclusive (menu prices already hold the GST, which is only shown).
 *
 * This layer only runs when the shop's plan has the billing module and the
 * owner has turned on GST or a service charge; otherwise a bill is exactly
 * what it always was: items − discounts + delivery.
 */
class TaxService
{
    public function __construct(private readonly PlanAccessService $access) {}

    /** Is the billing layer working for this shop right now? */
    public function active(Business $business): bool
    {
        if (! $this->access->allows($business, 'billing')) {
            return false;
        }
        $settings = $business->posSettings();

        return $settings->gst_enabled || (float) $settings->service_charge_percent > 0;
    }

    /** The mode a new order is priced in: 'exclusive', 'inclusive', 'none' (service charge only), or null (off). */
    public function modeFor(Business $business): ?string
    {
        if (! $this->active($business)) {
            return null;
        }
        $settings = $business->posSettings();
        if (! $settings->gst_enabled) {
            return 'none';
        }

        return $settings->prices_include_tax ? 'inclusive' : 'exclusive';
    }

    /**
     * Work out the bill's tail: service charge, GST, round-off and the total.
     *
     * @param  list<array{amount: float, rate: float|null}>  $lines  each line's value (unit × qty) and its own GST rate, if any
     * @param  float  $discount  every discount taken before tax, coins included
     * @param  string|null  $mode  from modeFor(), or an order's saved mode when it is repriced
     * @return array{mode: ?string, taxable: float, cgst: float, sgst: float, serviceCharge: float, serviceChargePercent: float, roundOff: float, total: float, lines: list<array{rate: ?float, taxable: ?float, tax: ?float}>}
     */
    public function compute(Business $business, array $lines, float $discount, float $deliveryFee, ?string $mode): array
    {
        $subtotal = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
        $net = max(0.0, round($subtotal - $discount, 2));

        if ($mode === null) {
            return [
                'mode' => null, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0,
                'serviceCharge' => 0.0, 'serviceChargePercent' => 0.0, 'roundOff' => 0.0,
                'total' => round($net + $deliveryFee, 2),
                'lines' => array_map(fn () => ['rate' => null, 'taxable' => null, 'tax' => null], $lines),
            ];
        }

        $settings = $business->posSettings();
        $default = (float) $settings->default_tax_rate;
        $scPct = max(0.0, (float) $settings->service_charge_percent);
        $share = $subtotal > 0 ? $net / $subtotal : 0.0;

        $out = [];
        $taxable = 0.0;
        $tax = 0.0;
        $serviceCharge = round($net * $scPct / 100, 2);
        $extra = 0.0; // what the customer pays beyond net + service charge + delivery

        foreach ($lines as $line) {
            $rate = $mode === 'none' ? 0.0 : (float) ($line['rate'] ?? $default);
            $lineNet = (float) $line['amount'] * $share;

            if ($mode === 'inclusive') {
                $base = $lineNet / (1 + $rate / 100);
                $lineTax = $lineNet - $base;
                // The service charge on this line's food value, and its GST on top.
                $scTax = $base * $scPct / 100 * $rate / 100;
                $tax += $lineTax + $scTax;
                $extra += $scTax;
                $taxable += $base;
                $out[] = ['rate' => $rate, 'taxable' => round($base, 2), 'tax' => round($lineTax, 2)];
            } else {
                $base = $lineNet * (1 + $scPct / 100);
                $lineTax = $base * $rate / 100;
                $tax += $lineTax;
                $extra += $lineTax;
                $taxable += $lineNet;
                $out[] = ['rate' => $rate, 'taxable' => round($lineNet, 2), 'tax' => round($lineTax, 2)];
            }
        }

        if ($mode === 'inclusive') {
            // Service charge is added on the pre-tax food value.
            $serviceCharge = round($taxable * $scPct / 100, 2);
            $taxable += $serviceCharge;
        } else {
            $taxable += $serviceCharge;
        }

        if ($deliveryFee > 0 && $mode !== 'none') {
            if ($mode === 'inclusive') {
                $dBase = $deliveryFee / (1 + $default / 100);
                $tax += $deliveryFee - $dBase;
                $taxable += $dBase;
            } else {
                $dTax = $deliveryFee * $default / 100;
                $tax += $dTax;
                $extra += $dTax;
                $taxable += $deliveryFee;
            }
        }

        $cgst = round($tax / 2, 2);
        $sgst = $cgst;
        // Exclusive: the GST is the extra. Inclusive: only the service charge's GST is.
        $extra = $mode === 'exclusive' ? $cgst + $sgst : round($extra, 2);
        $raw = round($net + $serviceCharge + $deliveryFee + $extra, 2);
        $total = $settings->round_off ? round($raw) : $raw;

        return [
            'mode' => $mode,
            'taxable' => round($taxable, 2),
            'cgst' => $cgst,
            'sgst' => $sgst,
            'serviceCharge' => $serviceCharge,
            'serviceChargePercent' => $scPct,
            'roundOff' => round($total - $raw, 2),
            'total' => round($total, 2),
            'lines' => $out,
        ];
    }

    /** The order's columns from a compute() result. @return array<string, mixed> */
    public static function orderColumns(array $bill): array
    {
        return [
            'tax_mode' => $bill['mode'],
            'taxable_amount' => $bill['taxable'],
            'cgst' => $bill['cgst'],
            'sgst' => $bill['sgst'],
            'service_charge' => $bill['serviceCharge'],
            'service_charge_percent' => $bill['serviceChargePercent'],
            'round_off' => $bill['roundOff'],
        ];
    }

    /**
     * Give a paid order its invoice number, once: sequential per shop per
     * financial year (April–March), as GST asks — "PFX/2627/0042", 16
     * characters at most. Call inside the transaction that marks it paid.
     */
    public function assignInvoiceNumber(Order $order): void
    {
        if ($order->invoice_no !== null || ! $this->access->allows($order->business, 'billing')) {
            return;
        }

        $local = Carbon::now(config('app.business_timezone', 'Asia/Kolkata'));
        $startYear = $local->month >= 4 ? $local->year : $local->year - 1;
        $fy = substr((string) $startYear, 2).substr((string) ($startYear + 1), 2);

        DB::table('invoice_counters')->insertOrIgnore(['business_id' => $order->business_id, 'fy' => $fy, 'last' => 0]);
        $counter = DB::table('invoice_counters')->where('business_id', $order->business_id)->where('fy', $fy)->lockForUpdate()->first();
        $next = (int) $counter->last + 1;
        DB::table('invoice_counters')->where('id', $counter->id)->update(['last' => $next]);

        $prefix = trim((string) $order->business->posSettings()->invoice_prefix);
        $number = ($prefix !== '' ? $prefix.'/' : '').$fy.'/'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $order->forceFill(['invoice_no' => $number])->save();
    }
}
