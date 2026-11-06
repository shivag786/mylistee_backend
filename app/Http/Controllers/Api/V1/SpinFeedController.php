<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOfferResource;
use App\Models\Business;
use App\Models\Spin;
use App\Services\SettingService;
use App\Services\SpinnerService;
use App\Support\ApiResponse;
use App\Support\FeedRanker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Spin & win for the customer home: every shop with a live wheel, nearest
 * first, with the prizes on its wheel. Public -- a guest sees the wheels and
 * is asked to sign in only to spin. Signed in, each shop says whether today's
 * spin is still there.
 *
 * Play/win counts ride along while the admin's "Show spin counts" is on.
 *
 * GET /spins?lat=&lng=&city=&limit=
 */
class SpinFeedController extends Controller
{
    private const POOL = 120;

    public function __construct(private readonly SpinnerService $spinner) {}

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 12), 30));
        $city = trim((string) $request->query('city', ''));
        $ranker = FeedRanker::fromRequest($request);
        $user = $request->user('sanctum');

        $shops = Business::query()
            ->where('status', BusinessStatus::Active->value)
            ->whereHas('offers', fn ($q) => $q->live())
            ->when($city !== '', fn ($q) => $q->where(fn ($w) => $w->where('city', $city)->orWhereNull('city')))
            ->with(['liveOffers'])
            ->latest('id')
            ->limit(self::POOL)
            ->get()
            ->sortBy(fn (Business $b) => [
                $ranker->isFollowed($b) ? 0 : 1,
                $ranker->distance($b) ?? PHP_INT_MAX,
                -$b->total_spins,
            ])
            ->take($limit)
            ->values();

        $show = self::showCounts();

        return ApiResponse::success(
            $shops->map(fn (Business $b) => [
                'slug' => $b->slug,
                'name' => $b->name,
                'logoUrl' => $b->logo_path ? Storage::disk('public')->url($b->logo_path) : null,
                'coverUrl' => $b->cover_path ? Storage::disk('public')->url($b->cover_path) : null,
                'distanceMeters' => $ranker->distance($b),
                'isOpen' => $b->isOpenNow(),
                'offers' => PublicOfferResource::collection($b->liveOffers),
                'alreadySpunToday' => $user !== null ? $this->spinner->hasSpunToday($user, $b) : null,
                ...self::counts($b),
            ])->all(),
            'Spin & win.',
            meta: ['showCounts' => $show],
        );
    }

    /** The admin's "Show spin counts" switch. */
    public static function showCounts(): bool
    {
        return (bool) app(SettingService::class)->get('spinShowCounts', true);
    }

    /**
     * People who played this shop's wheel and rewards won there, or nothing
     * at all while the admin hides counts.
     *
     * @return array{players?: int, wins?: int, playedToday?: int}
     */
    public static function counts(Business $business): array
    {
        if (! self::showCounts()) {
            return [];
        }

        return [
            'players' => Spin::where('business_id', $business->id)->distinct()->count('customer_id'),
            'wins' => (int) $business->total_rewards,
            'playedToday' => Spin::where('business_id', $business->id)->whereDate('created_at', today())->count(),
        ];
    }
}
