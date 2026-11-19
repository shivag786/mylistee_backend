<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\Business;
use App\Models\Product;
use App\Services\AiAdvisor\AiAdvisorService;
use App\Services\PlanAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * AI Sales Manager (`kind=sales`) and AI Customer Growth Advisor (`kind=growth`)
 * for the owner web panel and the vendor app. Under `business/ai/…`, so the
 * plan's AI assistant module gates every change (EnforcePlanModules).
 *
 *   GET  /business/ai/advisor?kind=sales|growth       ideas + the numbers behind them
 *   POST /business/ai/advisor/refresh {kind}          new ideas now (3 a day)
 *   POST /business/ai/advisor/{uuid}/approve {edits}  create the offer / combo / coupon (+ push)
 *   POST /business/ai/advisor/{uuid}/dismiss
 */
class AiAdvisorController extends Controller
{
    use ResolvesBusiness;

    public function __construct(
        private readonly AiAdvisorService $advisor,
        private readonly PlanAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $business = $this->business($request);

        $rows = AiSuggestion::query()->where('business_id', $business->id)->where('kind', $kind)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere('decided_at', '>=', now()->subDays(30)))
            ->latest('id')->limit(30)->get();
        $pending = $rows->where('status', 'pending')->values();

        return ApiResponse::success([
            'kind' => $kind,
            'available' => $this->advisor->available(),
            'inPlan' => $this->access->allows($business, 'ai_assistant'),
            'refreshesLeft' => $this->advisor->refreshesLeft($business, $kind),
            'generatedAt' => $pending->first()?->created_at?->toIso8601String(),
            'stats' => $pending->first()?->stats ?? $rows->first()?->stats,
            'suggestions' => $this->present($business, $pending),
            'history' => $this->present($business, $rows->where('status', '!=', 'pending')->where('status', '!=', 'expired')->values()),
        ], 'AI ideas.');
    }

    public function refresh(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $business = $this->business($request);
        if (! $this->advisor->available()) {
            return ApiResponse::error('AI ideas are switched off right now.', status: 503);
        }

        try {
            $rows = $this->advisor->refresh($business, $kind);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 502);
        }

        return ApiResponse::success([
            'suggestions' => $this->present($business, collect($rows)),
            'stats' => $rows[0]->stats ?? null,
            'refreshesLeft' => $this->advisor->refreshesLeft($business, $kind),
        ], count($rows).' new ideas.');
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $edits = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'message' => ['sometimes', 'nullable', 'string', 'max:300'],
            'pushTitle' => ['sometimes', 'string', 'max:60'],
            'value' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'minOrder' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'validityDays' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'timeFrom' => ['sometimes', 'nullable', 'date_format:H:i'],
            'timeTo' => ['sometimes', 'nullable', 'date_format:H:i'],
            'days' => ['sometimes', 'array'],
            'days.*' => ['string', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'productIds' => ['sometimes', 'array', 'max:10'],
            'productIds.*' => ['integer'],
            'segment' => ['sometimes', Rule::in(['everyone', 'followers', 'customers', 'lapsed'])],
            'sendTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'sendNow' => ['sometimes', 'boolean'],
            'offer' => ['sometimes', 'nullable', 'array'],
            'offer.type' => ['required_with:offer', Rule::in(['percent', 'flat'])],
            'offer.value' => ['required_with:offer', 'numeric', 'min:1', 'max:100000'],
            'offer.minOrder' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'offer.validityDays' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $s = $this->find($request, $uuid);

        $s = $this->advisor->approve($s, $request->user(), $edits);

        return ApiResponse::success($this->present($this->business($request), collect([$s]))[0], $s->result['summary'] ?? 'Approved.');
    }

    public function dismiss(Request $request, string $uuid): JsonResponse
    {
        $s = $this->advisor->dismiss($this->find($request, $uuid), $request->user());

        return ApiResponse::success($this->present($this->business($request), collect([$s]))[0], 'Idea dismissed.');
    }

    private function kind(Request $request): string
    {
        $data = $request->validate(['kind' => ['required', Rule::in([AiSuggestion::KIND_SALES, AiSuggestion::KIND_GROWTH])]]);

        return $data['kind'];
    }

    private function find(Request $request, string $uuid): AiSuggestion
    {
        $s = AiSuggestion::query()->where('business_id', $this->business($request)->id)->where('uuid', $uuid)->first();
        abort_if($s === null, 404, 'Idea not found.');

        return $s;
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(Business $business, $rows): array
    {
        $ids = $rows->flatMap(fn (AiSuggestion $s) => $s->payload['productIds'] ?? [])->unique()->all();
        $products = $ids === [] ? collect() : Product::query()->where('business_id', $business->id)->whereIn('id', $ids)
            ->get(['id', 'uuid', 'name', 'selling_price'])->keyBy('id');

        return $rows->map(function (AiSuggestion $s) use ($products) {
            $p = $s->payload ?? [];

            return [
                'id' => $s->uuid,
                'kind' => $s->kind,
                'type' => $s->type,
                'title' => $s->title,
                'message' => $s->message,
                'reasoning' => $s->reasoning,
                'status' => $s->status,
                'products' => collect($p['productIds'] ?? [])->map(fn ($id) => $products->get($id))->filter()
                    ->map(fn ($x) => ['id' => $x->id, 'productId' => $x->uuid, 'name' => $x->name, 'price' => (float) $x->selling_price])->values()->all(),
                'details' => $p,
                'result' => $s->result,
                'sendAt' => $s->send_at?->toIso8601String(),
                'sentAt' => $s->sent_at?->toIso8601String(),
                'createdAt' => $s->created_at?->toIso8601String(),
                'decidedAt' => $s->decided_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
