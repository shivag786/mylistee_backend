<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Services\ShopPushService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner's "Notify customers" screen: quota + audiences, and sending. */
class PushCampaignController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ShopPushService $push) {}

    /** GET /business/push */
    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return ApiResponse::success($this->push->summary($business), 'Notification summary.');
    }

    /** POST /business/push */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $data = $request->validate([
            'audience' => ['required', Rule::in(array_keys(ShopPushService::AUDIENCES))],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['nullable', 'string', 'max:200'],
            // In-app paths only (e.g. "/b/shop?item=…"), never an outside URL.
            'link' => ['nullable', 'string', 'max:255', 'regex:#^/[^/]#'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $campaign = $this->push->send($business, $request->user(), $data);

        return ApiResponse::success(
            ['id' => $campaign->uuid, 'recipients' => $campaign->recipients, 'reachable' => $campaign->reachable],
            "Sending to {$campaign->recipients} customers.",
            201,
        );
    }
}
