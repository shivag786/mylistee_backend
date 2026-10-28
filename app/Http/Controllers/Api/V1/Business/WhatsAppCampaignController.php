<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppCampaignService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner's "WhatsApp offers" screen: ready-made templates, quota, sending. */
class WhatsAppCampaignController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly WhatsAppCampaignService $campaigns) {}

    /** GET /business/whatsapp */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->campaigns->summary($this->business($request)), 'WhatsApp offers.');
    }

    /** POST /business/whatsapp */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'template' => ['required', 'string', 'uuid'],
            'audience' => ['required', Rule::in(array_keys(WhatsAppCampaignService::AUDIENCES))],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $campaign = $this->campaigns->send($business, $request->user(), $data);

        return ApiResponse::success(
            ['id' => $campaign->uuid, 'recipients' => $campaign->recipients],
            "Sending on WhatsApp to {$campaign->recipients} customers.",
            201,
        );
    }
}
