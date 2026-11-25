<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AiCreditPack;
use App\Models\AiUsage;
use App\Models\Business;
use App\Models\Payment;
use App\Services\AiAssistantService;
use App\Services\AiCreditService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → AI usage & packs.
 *
 *   GET    /admin/ai                 usage (today, month, by day, by shop), packs, pack sales, settings
 *   PUT    /admin/ai/settings        {ideasPerAnswer}
 *   POST   /admin/ai/packs           {name, answers, price, isActive}
 *   PATCH  /admin/ai/packs/{uuid}
 *   DELETE /admin/ai/packs/{uuid}
 *   POST   /admin/ai/credits         {businessId, answers (may be negative), note}
 *
 * The AI answers each plan gets a month are set on the plan itself (Plans).
 */
class AiCreditAdminController extends Controller
{
    public function __construct(
        private readonly AiAssistantService $ai,
        private readonly AiCreditService $credits,
        private readonly SettingService $settings,
    ) {}

    public function index(): JsonResponse
    {
        $month = now()->startOfMonth();
        $today = now()->startOfDay();

        $bySource = AiUsage::query()->where('created_at', '>=', $month)
            ->selectRaw('source, COUNT(*) as n')->groupBy('source')->pluck('n', 'source');

        $days = AiUsage::query()->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n')->groupBy('day')->pluck('n', 'day');
        $daily = collect(range(13, 0))->map(function ($ago) use ($days) {
            $d = now()->subDays($ago)->toDateString();

            return ['day' => $d, 'calls' => (int) ($days[$d] ?? 0)];
        })->values();

        $perShop = AiUsage::query()->whereNotNull('business_id')->where('created_at', '>=', $month)
            ->selectRaw("business_id,
                SUM(CASE WHEN source = 'plan' THEN 1 ELSE 0 END) as plan_used,
                SUM(CASE WHEN source = 'addon' THEN 1 ELSE 0 END) as addon_used,
                SUM(CASE WHEN source = 'scan' THEN 1 ELSE 0 END) as scans,
                COUNT(*) as calls,
                MAX(created_at) as last_used")
            ->groupBy('business_id')->orderByDesc('calls')->limit(100)->get()->keyBy('business_id');

        // Shops holding bought answers but quiet this month still show.
        $ids = $perShop->keys()->merge(Business::query()->where('ai_credits', '>', 0)->limit(100)->pluck('id'))->unique();
        $shops = Business::query()->whereIn('id', $ids)->get(['id', 'uuid', 'name', 'city', 'ai_credits'])
            ->map(function (Business $b) use ($perShop) {
                $row = $perShop->get($b->id);
                $plan = $b->currentPlan();

                return [
                    'id' => $b->uuid,
                    'name' => $b->name,
                    'city' => $b->city,
                    'plan' => $plan?->name,
                    'planLimit' => $this->ai->planLimit($b),
                    'planUsed' => (int) ($row->plan_used ?? 0),
                    'addonUsed' => (int) ($row->addon_used ?? 0),
                    'scans' => (int) ($row->scans ?? 0),
                    'calls' => (int) ($row->calls ?? 0),
                    'credits' => (int) $b->ai_credits,
                    'lastUsed' => $row?->last_used ? (string) $row->last_used : null,
                ];
            })->sortByDesc('calls')->values();

        $sales = Payment::query()->where('purpose', AiCreditService::PURPOSE)->where('status', PaymentStatus::Captured);

        return ApiResponse::success([
            'enabled' => $this->ai->enabled(),
            'provider' => $this->ai->provider(),
            'model' => $this->settings->get($this->ai->provider() === 'openai' ? 'openaiModel' : 'geminiModel'),
            'ideasPerAnswer' => $this->ai->ideasPerAnswer(),
            'today' => AiUsage::query()->where('created_at', '>=', $today)->count(),
            'month' => (int) $bySource->sum(),
            'bySource' => [
                'plan' => (int) ($bySource['plan'] ?? 0),
                'addon' => (int) ($bySource['addon'] ?? 0),
                'scan' => (int) ($bySource['scan'] ?? 0),
                'system' => (int) ($bySource['system'] ?? 0),
                'refresh' => (int) ($bySource['refresh'] ?? 0),
            ],
            'daily' => $daily,
            'shops' => $shops,
            'packs' => AiCreditPack::query()->orderBy('position')->orderBy('price')->get()
                ->map(fn (AiCreditPack $p) => $this->credits->presentPack($p))->values(),
            'packSales' => [
                'monthCount' => (clone $sales)->where('paid_at', '>=', $month)->count(),
                'monthAmount' => (float) (clone $sales)->where('paid_at', '>=', $month)->sum('amount'),
                'totalAmount' => (float) (clone $sales)->sum('amount'),
                'creditsOutstanding' => (int) Business::query()->sum('ai_credits'),
            ],
        ], 'AI usage.');
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['ideasPerAnswer' => ['required', 'integer', 'min:1', 'max:10']]);
        $this->settings->set(['aiIdeasPerAnswer' => $data['ideasPerAnswer']]);

        return ApiResponse::success(['ideasPerAnswer' => $this->ai->ideasPerAnswer()], 'Saved.');
    }

    public function storePack(Request $request): JsonResponse
    {
        $pack = AiCreditPack::query()->create($this->packData($request, true));

        return ApiResponse::success($this->credits->presentPack($pack), 'AI pack added.', 201);
    }

    public function updatePack(Request $request, string $uuid): JsonResponse
    {
        $pack = AiCreditPack::query()->where('uuid', $uuid)->firstOrFail();
        $pack->update($this->packData($request, false));

        return ApiResponse::success($this->credits->presentPack($pack->fresh()), 'AI pack saved.');
    }

    public function destroyPack(string $uuid): JsonResponse
    {
        // Sold packs live on in their payments' meta, so deleting is safe.
        AiCreditPack::query()->where('uuid', $uuid)->delete();

        return ApiResponse::success(null, 'AI pack deleted.');
    }

    public function grant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'businessId' => ['required', 'string', 'max:64'],
            'answers' => ['required', 'integer', 'between:-100000,100000', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $business = Business::query()->where('uuid', $data['businessId'])->firstOrFail();
        $balance = $this->credits->grant($business, (int) $data['answers'], $request->user(), $data['note'] ?? null);

        return ApiResponse::success(['credits' => $balance], 'AI answers updated.');
    }

    /** @return array<string, mixed> */
    private function packData(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'answers' => [$req, 'integer', 'min:1', 'max:100000'],
            'price' => [$req, 'numeric', 'min:1', 'max:1000000'],
            'isActive' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        return array_filter([
            'name' => $data['name'] ?? null,
            'answers' => $data['answers'] ?? null,
            'price' => $data['price'] ?? null,
            'is_active' => $data['isActive'] ?? null,
            'position' => $data['position'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
