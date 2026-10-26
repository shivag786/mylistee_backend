<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kitchen screen in the vendor app: accepted orders still to be made,
 * the ones just made (waiting to be handed over), and a running total of
 * every dish to make across them — what a cook on a food cart batches.
 */
class KitchenController extends Controller
{
    use ResolvesBusiness;

    /** How long a ready order stays on the screen when nobody completes it. */
    private const READY_FOR_HOURS = 3;

    /** GET /business/kitchen */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $active = [OrderStatus::Confirmed->value, OrderStatus::Paid->value];
        $with = ['items', 'customer:id,name', 'diningTable:id,label'];

        $cooking = $business->orders()
            ->whereIn('status', $active)
            ->whereIn('kitchen_status', [KitchenStatus::Queued->value, KitchenStatus::Preparing->value])
            ->with($with)
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $ready = $business->orders()
            ->whereIn('status', $active)
            ->where('kitchen_status', KitchenStatus::Ready->value)
            ->where('ready_at', '>=', now()->subHours(self::READY_FOR_HOURS))
            ->with($with)
            ->latest('ready_at')
            ->limit(30)
            ->get();

        return ApiResponse::success([
            'kitchenEnabled' => $business->usesKitchen(),
            'orders' => OrderResource::collection($cooking),
            'ready' => OrderResource::collection($ready),
            'toMake' => $this->toMake($cooking),
        ], 'Kitchen.');
    }

    /**
     * PUT /business/kitchen { enabled: true|false|null } — the owner's switch.
     * Null goes back to what the shop type suggests.
     */
    public function setting(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $validated = $request->validate(['enabled' => ['present', 'nullable', 'boolean']]);

        $business->forceFill(['kitchen_enabled' => $validated['enabled']])->save();

        return ApiResponse::success(
            new BusinessResource($business->load(['category', 'gallery', 'qrCode'])),
            $business->usesKitchen() ? 'Kitchen screen is on.' : 'Kitchen screen is off.',
        );
    }

    /**
     * Every dish still to make, added up across orders. A line's name already
     * carries its size ("Veg Momos (Half)"); add-ons keep lines apart.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return list<array{name: string, addons: list<string>, quantity: int, tokens: list<string>}>
     */
    private function toMake($orders): array
    {
        $lines = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $addons = array_values($item->options['addons'] ?? []);
                $key = $item->name.'|'.implode(',', $addons);
                $lines[$key] ??= ['name' => $item->name, 'addons' => $addons, 'quantity' => 0, 'tokens' => []];
                $lines[$key]['quantity'] += (int) $item->quantity;
                if (! in_array($order->token, $lines[$key]['tokens'], true)) {
                    $lines[$key]['tokens'][] = $order->token;
                }
            }
        }

        $lines = array_values($lines);
        usort($lines, fn ($a, $b) => [$b['quantity'], $a['name']] <=> [$a['quantity'], $b['name']]);

        return $lines;
    }
}
