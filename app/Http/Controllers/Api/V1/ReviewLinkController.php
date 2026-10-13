<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Business;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ShopCustomer;
use App\Services\ReviewService;
use App\Services\ShopCustomerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A shop's public review link (/review/{slug}), shared by the owner with their
 * customers. No account and no OTP: the reviewer gives a mobile number, which
 * files them in the shop's customer book, plus any of an overall star, a text
 * review and per-item stars. Abuse is kept down by rate limits (per address on
 * the route, per mobile here) and one editable review per mobile per shop.
 */
class ReviewLinkController extends Controller
{
    /** Submissions allowed per mobile per shop per hour (edits included). */
    private const PER_PHONE_PER_HOUR = 5;

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ShopCustomerService $customers,
    ) {}

    /** GET /review-link/{slug} — the shop and its items to rate. */
    public function show(string $slug): JsonResponse
    {
        $business = $this->activeBusiness($slug);

        $products = $business->products()->where('is_visible', true)
            ->orderBy('position')->latest('id')->get();
        $combos = $business->combos()->where('is_visible', true)
            ->orderBy('position')->latest('id')->get();

        $image = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;

        // Price and a highlight label are for the "try these next" cards shown
        // after a review is posted; the rating list only needs name + photo.
        $item = fn (string $type) => fn (Product|Combo $m) => $m instanceof Product
            ? [
                'type' => $type,
                'id' => $m->uuid,
                'name' => $m->name,
                'imageUrl' => $image($m->image_path),
                'price' => (float) $m->selling_price,
                'strikePrice' => $m->mrp !== null && (float) $m->mrp > (float) $m->selling_price ? (float) $m->mrp : null,
                'foodType' => $m->food_type?->value,
                'highlight' => match (true) {
                    $m->is_bestseller => 'Bestseller',
                    $m->is_todays_special => "Today's special",
                    $m->is_recommended => 'Recommended',
                    default => null,
                },
                'inStock' => (bool) $m->in_stock,
            ]
            : [
                'type' => $type,
                'id' => $m->uuid,
                'name' => $m->name,
                'imageUrl' => $image($m->image_path),
                'price' => (float) $m->combo_price,
                'strikePrice' => null,
                'foodType' => null,
                'highlight' => 'Combo',
                'inStock' => true,
            ];

        return ApiResponse::success([
            'name' => $business->name,
            'slug' => $business->slug,
            'logoUrl' => $business->logo_path ? Storage::disk('public')->url($business->logo_path) : null,
            'items' => [
                ...$products->map($item('product'))->all(),
                ...$combos->map($item('combo'))->all(),
            ],
        ], 'Review link.');
    }

    /**
     * POST /review-link/{slug}
     * { phone, name?, rating?, comment?, items?: [{ type, id, rating }], website? }
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $business = $this->activeBusiness($slug);

        // Honeypot: a field real visitors never see. Bots that fill every input
        // get a normal-looking success and nothing is saved.
        if (filled($request->input('website'))) {
            return ApiResponse::success(message: 'Thanks for your review!', status: 201);
        }

        $request->merge(['phone' => ShopCustomer::normalizePhone($request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'name' => ['nullable', 'string', 'max:80'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.type' => ['required', Rule::in(['product', 'combo'])],
            'items.*.id' => ['required', 'string'],
            'items.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
        ], [
            'phone.required' => 'Enter your mobile number.',
            'phone.regex' => 'Enter a valid 10-digit mobile number.',
        ]);

        $name = $this->clean($data['name'] ?? null);
        $comment = $this->clean($data['comment'] ?? null);
        $rating = isset($data['rating']) ? (int) $data['rating'] : null;
        $items = $this->resolveItems($business, $data['items'] ?? []);

        if ($rating === null && $comment === null && $items === []) {
            throw ValidationException::withMessages([
                'comment' => ['Add a star rating or write a few words.'],
            ]);
        }

        $key = "review-link:{$business->id}:{$data['phone']}";
        if (RateLimiter::tooManyAttempts($key, self::PER_PHONE_PER_HOUR)) {
            return ApiResponse::error('Too many reviews from this number. Please try again later.', status: 429);
        }
        RateLimiter::hit($key, 3600);

        $reviewer = $this->customers->findOrCreateFromReview($business, $data['phone'], $name);
        $review = $this->reviews->upsertFromLink($business, $reviewer, $name, $rating, $comment, $items);

        return ApiResponse::success(new ReviewResource($review), 'Thanks for your review!', status: 201);
    }

    private function activeBusiness(string $slug): Business
    {
        return Business::where('slug', $slug)
            ->where('status', BusinessStatus::Active->value)
            ->firstOrFail();
    }

    /**
     * Maps the submitted item ids to this shop's visible products/combos.
     * Ids that don't match (another shop's, or hidden since the page loaded)
     * are dropped rather than failing the whole review. One rating per item.
     *
     * @param  array<int, array{type: string, id: string, rating: int|string}>  $submitted
     * @return list<array{type: string, id: int, name: string, rating: int}>
     */
    private function resolveItems(Business $business, array $submitted): array
    {
        $ids = fn (string $type) => collect($submitted)->where('type', $type)->pluck('id')->all();

        $found = [
            'product' => $business->products()->where('is_visible', true)
                ->whereIn('uuid', $ids('product'))->get(['id', 'uuid', 'name'])->keyBy('uuid'),
            'combo' => $business->combos()->where('is_visible', true)
                ->whereIn('uuid', $ids('combo'))->get(['id', 'uuid', 'name'])->keyBy('uuid'),
        ];

        $items = [];
        foreach ($submitted as $entry) {
            $model = $found[$entry['type']]->get($entry['id']);
            $key = "{$entry['type']}:{$entry['id']}";
            if ($model === null || isset($items[$key])) {
                continue;
            }
            $items[$key] = [
                'type' => $entry['type'],
                'id' => $model->id,
                'name' => $model->name,
                'rating' => (int) $entry['rating'],
            ];
        }

        return array_values($items);
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
