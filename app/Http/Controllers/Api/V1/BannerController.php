<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public homepage banners — only live (active + in-schedule) banners, grouped by
 * placement slot so the homepage renders each carousel with one request.
 */
class BannerController extends Controller
{
    /** GET /banners */
    public function index(): JsonResponse
    {
        $banners = Banner::query()->live()->orderBy('position')->orderBy('id')->get();

        $grouped = [];
        foreach (BannerPlacement::cases() as $placement) {
            $grouped[$placement->value] = BannerResource::collection(
                $banners->where('placement', $placement)->values(),
            );
        }

        return ApiResponse::success($grouped, 'Banners retrieved.');
    }
}
