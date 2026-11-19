<?php

namespace App\Services;

use App\Enums\FoodType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * AI menu scan: the owner photographs (or uploads a PDF / screenshot of) their
 * printed menu, Gemini reads the dishes, sections, prices, sizes and veg marks,
 * and the owner checks the list before anything is saved. Nothing is created
 * until import() runs with the list the owner confirmed and edited.
 *
 * Runs on the admin's Gemini key (Admin → Settings → AI assistant) for every
 * shop — it is how a new shop fills its menu, so it is not a plan module — with
 * its own monthly cap per shop (`aiMenuScanLimit`).
 */
class MenuScanService
{
    /** Files Gemini can read inline. */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];

    public const MAX_FILES = 6;

    /** Gemini's inline request limit is 20 MB; leave room for base64 growth. */
    private const MAX_TOTAL_BYTES = 14 * 1024 * 1024;

    private const MAX_ITEMS = 300;

    public function __construct(
        private readonly AiAssistantService $ai,
        private readonly ProductService $products,
        private readonly SettingService $settings,
    ) {}

    /** On when the AI assistant is on with a Gemini key (menu reading needs Gemini's vision). */
    public function enabled(): bool
    {
        return $this->ai->enabled() && $this->ai->provider() === 'gemini';
    }

    public function monthlyLimit(): int
    {
        return max(0, (int) $this->settings->get('aiMenuScanLimit', 30));
    }

    public function usedThisMonth(Business $business): int
    {
        return (int) $this->counter()->get($this->usageKey($business), 0);
    }

    /**
     * Read the menu from the photos / PDF pages.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, category: ?string, price: ?float, description: ?string, foodType: ?string, variants: list<array{name: string, price: float}>, exists: bool}>
     *
     * @throws ValidationException when over the limit or the files are unusable
     * @throws RuntimeException when the AI fails or finds no dishes
     */
    public function scan(Business $business, array $files): array
    {
        if ($this->usedThisMonth($business) >= $this->monthlyLimit()) {
            throw ValidationException::withMessages([
                'files' => ['You have used all your menu scans for this month. They refresh on the 1st.'],
            ]);
        }

        $total = array_sum(array_map(fn (UploadedFile $f) => (int) $f->getSize(), $files));
        if ($total > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'files' => ['These photos are too big together. Send fewer pages at a time (up to 14 MB).'],
            ]);
        }

        $parts = [['text' => $this->prompt($business, count($files))]];
        foreach ($files as $file) {
            $parts[] = ['inline_data' => [
                'mime_type' => $this->mimeOf($file),
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
            ]];
        }

        $system = 'You read restaurant and shop menus from photos for Listee, an Indian local-shop app. '
            .'Copy what is printed — never invent dishes, prices or descriptions. Reply with JSON only.';

        $content = $this->ai->askGemini($system, $parts, temperature: 0.1, timeout: 120);
        $json = $this->ai->decodeJson($content);
        $raw = $json['items'] ?? (is_array($json) && array_is_list($json) ? $json : null);

        $items = $this->normalize(is_array($raw) ? $raw : [], $business);
        if ($items === []) {
            Log::info('Menu scan found nothing', ['business' => $business->id, 'content' => mb_substr($content, 0, 300)]);
            throw new RuntimeException('No dishes could be read. Take a clear, straight photo of the menu in good light and try again.');
        }

        $this->counter()->put($this->usageKey($business), $this->usedThisMonth($business) + 1, now()->endOfMonth()->addDay());

        return $items;
    }

    /**
     * Add the dishes the owner confirmed. Sections are created by name.
     * Stops cleanly at the plan's product limit.
     *
     * @param  list<array<string, mixed>>  $items  validated rows from the review screen
     * @return array{created: int, skipped: int, failed: list<array{name: string, reason: string}>, limitReached: bool}
     */
    public function import(Business $business, User $actor, array $items, bool $skipExisting = true): array
    {
        $existing = $business->products()->pluck('name')->map(fn ($n) => $this->key((string) $n))->flip();
        $created = 0;
        $skipped = 0;
        $failed = [];
        $limitReached = false;

        foreach ($items as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($skipExisting && $existing->has($this->key($name))) {
                $skipped++;

                continue;
            }

            $variants = array_values(array_filter(
                $row['variants'] ?? [],
                fn ($v) => is_array($v) && filled($v['name'] ?? null) && is_numeric($v['price'] ?? null),
            ));
            $price = is_numeric($row['price'] ?? null)
                ? (float) $row['price']
                : ($variants !== [] ? min(array_map(fn ($v) => (float) $v['price'], $variants)) : 0.0);

            $data = array_filter([
                'name' => mb_substr($name, 0, 120),
                'category_name' => filled($row['category'] ?? null) ? mb_substr(trim((string) $row['category']), 0, 80) : null,
                'description' => filled($row['description'] ?? null) ? mb_substr(trim((string) $row['description']), 0, 1000) : null,
                'selling_price' => round($price, 2),
                'food_type' => FoodType::tryFrom((string) ($row['foodType'] ?? ''))?->value,
                'variants' => $variants !== [] ? array_map(fn ($v) => [
                    'name' => mb_substr(trim((string) $v['name']), 0, 40),
                    'price' => round((float) $v['price'], 2),
                ], $variants) : null,
            ], fn ($v) => $v !== null);

            try {
                DB::transaction(fn () => $this->products->create($business, $data, null, $actor));
                $existing->put($this->key($name), true);
                $created++;
            } catch (ValidationException $e) {
                // The plan's product limit: everything after this would fail too.
                $failed[] = ['name' => $name, 'reason' => collect($e->errors())->flatten()->first() ?? 'Not saved.'];
                if (str_contains(json_encode($e->errors()) ?: '', 'Upgrade')) {
                    $limitReached = true;
                    break;
                }
            } catch (Throwable $e) {
                Log::warning('Menu scan import failed for a dish', ['name' => $name, 'error' => $e->getMessage()]);
                $failed[] = ['name' => $name, 'reason' => 'Could not be saved.'];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'limitReached' => $limitReached];
    }

    private function prompt(Business $business, int $pages): string
    {
        $business->loadMissing('category');
        $sections = $business->productCategories()->pluck('name')->all();

        return 'These '.($pages > 1 ? "{$pages} images are pages" : 'image is a page')
            .' of the menu of '.$business->name.($business->category ? ' ('.$business->category->name.')' : '').'. '
            .'List every dish or product on it, in menu order. For each give: '
            .'"name" (as printed, fix obvious spelling, Title Case), '
            .'"category" (the menu section heading it sits under, e.g. "Starters", "Beverages"; null if none), '
            .'"price" (number in rupees, no symbol; null if the item only has size prices), '
            .'"variants" (sizes or portions with their own price, e.g. [{"name":"Half","price":120},{"name":"Full","price":200}]; [] if one price), '
            .'"description" (only if a description is printed; else null), '
            .'"foodType" ("veg" for a green mark or veg section, "non_veg" for a red/brown mark or meat/fish, "egg" for egg dishes; null if unclear). '
            .'Skip taxes, phone numbers, timings, addresses and offers text. '
            .($sections !== [] ? 'The shop already uses these sections, reuse them when they fit: '.implode(', ', array_slice($sections, 0, 30)).'. ' : '')
            .'Shape: {"items":[{"name":"Paneer Tikka","category":"Starters","price":180,"variants":[],"description":null,"foodType":"veg"}]}';
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<array{name: string, category: ?string, price: ?float, description: ?string, foodType: ?string, variants: list<array{name: string, price: float}>, exists: bool}>
     */
    private function normalize(array $raw, Business $business): array
    {
        $existing = $business->products()->pluck('name')->map(fn ($n) => $this->key((string) $n))->flip();
        $seen = [];
        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', (string) ($row['name'] ?? '')) ?? '');
            if ($name === '' || mb_strlen($name) > 120) {
                continue;
            }
            $category = trim((string) ($row['category'] ?? ''));
            $dedupe = $this->key($name).'|'.$this->key($category);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $variants = [];
            foreach (is_array($row['variants'] ?? null) ? $row['variants'] : [] as $v) {
                $price = $this->price($v['price'] ?? null);
                if (is_array($v) && filled($v['name'] ?? null) && $price !== null) {
                    $variants[] = ['name' => mb_substr(trim((string) $v['name']), 0, 40), 'price' => $price];
                }
            }
            $variants = array_slice($variants, 0, 10);
            $price = $this->price($row['price'] ?? null);

            $out[] = [
                'name' => $name,
                'category' => $category !== '' ? mb_substr($category, 0, 80) : null,
                'price' => $price ?? ($variants !== [] ? min(array_column($variants, 'price')) : null),
                'description' => filled($row['description'] ?? null) ? mb_substr(trim((string) $row['description']), 0, 300) : null,
                'foodType' => FoodType::tryFrom((string) ($row['foodType'] ?? ''))?->value,
                'variants' => count($variants) > 1 ? $variants : [],
                'exists' => $existing->has($this->key($name)),
            ];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /** "₹1,250/-" → 1250.0; null when there is no usable number. */
    private function price(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? round((float) $value, 2) : null;
        }
        if (is_string($value) && preg_match('/\d[\d,]*(\.\d+)?/', $value, $m)) {
            return round((float) str_replace(',', '', $m[0]), 2);
        }

        return null;
    }

    private function mimeOf(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return in_array($mime, self::MIME_TYPES, true) ? $mime : 'image/jpeg';
    }

    private function key(string $name): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', $name) ?? $name);
    }

    /**
     * Where the monthly counts live: the limiter store (file in production,
     * where the default cache is `null` and would forget every count).
     */
    private function counter(): Repository
    {
        return Cache::store(config('cache.limiter') ?: 'file');
    }

    private function usageKey(Business $business): string
    {
        return 'menu-scan:'.$business->id.':'.now()->format('Y-m');
    }
}
