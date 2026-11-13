<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\BusinessPosSetting;
use App\Models\OwnerAlert;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The owner's alerts, and which kinds they want. */
class AlertController extends Controller
{
    use ResolvesBusiness;

    /** GET /business/alerts?type=&page= */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $page = OwnerAlert::where('business_id', $business->id)
            ->when($request->string('type')->trim()->value(), fn ($q, $t) => $q->where('type', $t))
            ->with('actor:id,name')
            ->latest('id')
            ->paginate(30);

        return ApiResponse::success([
            'alerts' => collect($page->items())->map(fn (OwnerAlert $a) => [
                'id' => $a->uuid,
                'type' => $a->type,
                'severity' => $a->severity,
                'title' => $a->title,
                'body' => $a->body,
                'data' => $a->data,
                'by' => $a->actor?->name,
                'read' => $a->read_at !== null,
                'at' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
            'unread' => OwnerAlert::where('business_id', $business->id)->whereNull('read_at')->count(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
        ], 'Alerts.');
    }

    /** POST /business/alerts/read — mark all read. */
    public function read(Request $request): JsonResponse
    {
        OwnerAlert::where('business_id', $this->business($request)->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(null, 'Marked read.');
    }

    /** GET /business/alerts/settings */
    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->business($request)->posSettings()->alerts(), 'Alert settings.');
    }

    /** PUT /business/alerts/settings */
    public function updateSettings(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $bools = array_keys(array_filter(BusinessPosSetting::ALERT_DEFAULTS, 'is_bool'));
        $data = $request->validate(array_fill_keys(array_map(fn ($k) => $k, $bools), ['sometimes', 'boolean']) + [
            'discountPercent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'cashDifferenceAmount' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'orderWaitingMinutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'dailySummaryTime' => ['sometimes', 'date_format:H:i'],
        ]);
        $settings = $business->posSettings();
        $settings->update(['alert_settings' => array_merge($settings->alerts(), $data)]);

        return ApiResponse::success($settings->fresh()->alerts(), 'Alert settings saved.');
    }
}
