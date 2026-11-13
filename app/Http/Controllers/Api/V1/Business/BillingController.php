<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessPosSetting;
use App\Services\PlanAccessService;
use App\Services\TaxService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GST billing settings: GSTIN and legal name for the bill, tax-inclusive or
 * not, the default GST rate, a service charge, round-off, the invoice prefix
 * and the business day's start hour. Staff can read them (the bill printer
 * needs them); only the owner changes them.
 */
class BillingController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly TaxService $tax, private readonly PlanAccessService $access) {}

    /** GET /business/billing */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($this->business($request)), 'Billing settings.');
    }

    /** PUT /business/billing */
    public function update(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'gstEnabled' => ['sometimes', 'boolean'],
            // 15-character GSTIN: 2-digit state, PAN, entity, Z, check.
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'legalName' => ['nullable', 'string', 'max:160'],
            'fssai' => ['nullable', 'string', 'regex:/^[0-9]{14}$/'],
            'pricesIncludeTax' => ['sometimes', 'boolean'],
            'defaultTaxRate' => ['sometimes', 'numeric', Rule::in([0, 5, 12, 18, 28])],
            'serviceChargePercent' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'roundOff' => ['sometimes', 'boolean'],
            'invoicePrefix' => ['nullable', 'string', 'max:5', 'regex:/^[A-Za-z0-9]*$/'],
            'billFooter' => ['nullable', 'string', 'max:200'],
            'dayStartHour' => ['sometimes', 'integer', 'min:0', 'max:8'],
        ], [
            'gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 08ABCDE1234F1Z5.',
            'fssai.regex' => 'FSSAI licence number is 14 digits.',
            'invoicePrefix.regex' => 'Use letters and numbers only.',
        ]);

        if (! empty($data['gstEnabled']) && empty($data['gstin'] ?? $business->gst)) {
            return ApiResponse::error('Add your GSTIN to charge GST.', ['gstin' => ['Add your GSTIN to charge GST.']], 422);
        }

        if (array_key_exists('gstin', $data)) {
            $business->forceFill(['gst' => $data['gstin'] !== null ? strtoupper($data['gstin']) : null])->save();
        }

        $map = [
            'gstEnabled' => 'gst_enabled', 'legalName' => 'legal_name', 'fssai' => 'fssai',
            'pricesIncludeTax' => 'prices_include_tax', 'defaultTaxRate' => 'default_tax_rate',
            'serviceChargePercent' => 'service_charge_percent', 'roundOff' => 'round_off',
            'invoicePrefix' => 'invoice_prefix', 'billFooter' => 'bill_footer', 'dayStartHour' => 'day_start_hour',
        ];
        $fields = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $key === 'invoicePrefix' && $data[$key] !== null ? strtoupper($data[$key]) : $data[$key];
            }
        }
        $business->posSettings()->update($fields);
        $business->unsetRelation('posSetting');

        return ApiResponse::success($this->payload($business), 'Billing settings saved.');
    }

    /** @return array<string, mixed> */
    private function payload(Business $business): array
    {
        /** @var BusinessPosSetting $s */
        $s = $business->posSettings();

        return [
            'available' => $this->access->allows($business, 'billing'),
            'active' => $this->tax->active($business),
            'gstEnabled' => $s->gst_enabled,
            'gstin' => $business->gst,
            'legalName' => $s->legal_name,
            'fssai' => $s->fssai,
            'pricesIncludeTax' => $s->prices_include_tax,
            'defaultTaxRate' => (float) $s->default_tax_rate,
            'serviceChargePercent' => (float) $s->service_charge_percent,
            'roundOff' => $s->round_off,
            'invoicePrefix' => $s->invoice_prefix,
            'billFooter' => $s->bill_footer,
            'dayStartHour' => $s->day_start_hour,
            // Restaurant service (SAC) code printed on GST bills.
            'sac' => '996331',
        ];
    }
}
