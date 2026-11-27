<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI menu scan: photo → dishes to review → products. */
class MenuScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Sharma Dhaba'])];
    }

    private function aiOn(int $limit = 30): void
    {
        app(SettingService::class)->set(['aiEnabled' => true, 'geminiApiKey' => 'AQ.test', 'aiMenuScanLimit' => $limit]);
    }

    private function fakeGemini(array $items): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items])]]]]],
            ]),
        ]);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('menu.jpg', 600, 800);
    }

    public function test_scan_reads_dishes_and_marks_existing_ones(): void
    {
        $this->aiOn();
        [$owner, $business] = $this->shop();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Masala Chai']);
        $this->fakeGemini([
            ['name' => 'Dal Makhani', 'category' => 'Main Course', 'price' => null, 'foodType' => 'veg', 'description' => 'Slow cooked',
                'variants' => [['name' => 'Half', 'price' => '₹140'], ['name' => 'Full', 'price' => 220]]],
            ['name' => 'masala chai', 'category' => 'Beverages', 'price' => '20/-', 'foodType' => 'veg', 'variants' => []],
            ['name' => 'Dal Makhani', 'category' => 'Main Course', 'price' => 140], // duplicate
            ['name' => '', 'price' => 10], // junk
            ['name' => 'Chicken Tikka', 'category' => null, 'price' => 240, 'foodType' => 'chicken'], // unknown food type
        ]);

        $this->as($owner)->getJson('/api/v1/business/menu-scan')->assertOk()->assertJsonPath('data.available', true);

        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.price', 140)
            ->assertJsonPath('data.items.0.variants.1.price', 220)
            ->assertJsonPath('data.items.1.price', 20)
            ->assertJsonPath('data.items.1.exists', true)
            ->assertJsonPath('data.items.2.foodType', null)
            ->assertJsonPath('data.used', 1);

        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'inline_data')
            && str_contains(json_encode($r->data()), 'Sharma Dhaba'));
    }

    public function test_thali_keeps_its_full_contents_as_one_tidy_description(): void
    {
        $this->aiOn();
        [$owner] = $this->shop();
        $long = "4 Butter Roti\n- Dal Makhani\n• Paneer Butter Masala\nMix Veg, Jeera Rice\n\nRaita, Salad, Papad, Gulab Jamun. "
            .str_repeat('Unlimited refill of dal and roti on request. ', 6);
        $this->fakeGemini([
            ['name' => 'Special Thali', 'category' => 'Thali', 'price' => 249, 'description' => $long],
            ['name' => 'Mini Thali', 'category' => 'Thali', 'price' => 149, 'description' => ['3 Roti', 'Dal', 'Sabzi', 'Rice']],
        ]);

        $res = $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.description', '3 Roti, Dal, Sabzi, Rice');

        $desc = $res->json('data.items.0.description');
        $this->assertStringStartsWith('4 Butter Roti, Dal Makhani, Paneer Butter Masala, Mix Veg, Jeera Rice, Raita, Salad, Papad, Gulab Jamun.', $desc);
        $this->assertGreaterThan(300, mb_strlen($desc)); // no longer cut at 300
        $this->assertStringNotContainsString("\n", $desc);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'THALIS')
            && ($r->data()['generationConfig']['maxOutputTokens'] ?? null) === 32768);
    }

    public function test_an_answer_cut_off_mid_list_keeps_the_complete_items(): void
    {
        $this->aiOn();
        [$owner] = $this->shop();
        $cut = '{"items":[{"name":"Tea","price":10},{"name":"Coffee","price":20},{"name":"Thali","price":200,"description":"4 Roti, Da';
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $cut]]]]],
            ]),
        ]);

        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.name', 'Coffee');
    }

    public function test_import_creates_products_sections_and_sizes_and_skips_existing(): void
    {
        [$owner, $business] = $this->shop();
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Masala Chai']);

        $this->as($owner)->postJson('/api/v1/business/menu-scan/import', ['items' => [
            ['name' => 'Dal Makhani', 'category' => 'Main Course', 'price' => null, 'foodType' => 'veg',
                'variants' => [['name' => 'Half', 'price' => 140], ['name' => 'Full', 'price' => 220]]],
            ['name' => 'Masala chai', 'category' => 'Beverages', 'price' => 20],
            ['name' => 'Butter Naan', 'category' => 'Breads', 'price' => 40, 'foodType' => 'veg'],
        ]])
            ->assertCreated()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);

        $dal = $business->products()->where('name', 'Dal Makhani')->with('category')->firstOrFail();
        $this->assertSame('Main Course', $dal->category->name);
        $this->assertEquals(140, (float) $dal->selling_price);
        $this->assertCount(2, $dal->options['variants']);
        $this->assertSame('veg', $dal->food_type?->value ?? $dal->food_type);
        $this->assertSame(1, $business->productCategories()->where('name', 'Breads')->count());
    }

    public function test_import_keeps_same_names_in_different_sections_and_hindi_names(): void
    {
        [$owner, $business] = $this->shop();

        $items = [
            ['name' => 'Veg', 'category' => 'Steamed Momos', 'price' => 60],
            ['name' => 'Paneer', 'category' => 'Steamed Momos', 'price' => 80],
            ['name' => 'Veg', 'category' => 'Fried Momos', 'price' => 70],
            ['name' => 'Paneer', 'category' => 'fried momos ', 'price' => 90],
            ['name' => 'पनीर टिक्का', 'category' => 'स्टार्टर', 'price' => 180],
            ['name' => 'दाल मखनी', 'category' => 'मेन कोर्स', 'price' => 160],
            ['name' => 'आलू पराठा', 'category' => 'मेन कोर्स', 'price' => 50],
        ];

        $this->as($owner)->postJson('/api/v1/business/menu-scan/import', ['items' => $items])
            ->assertCreated()
            ->assertJsonPath('data.created', 7)
            ->assertJsonPath('data.skipped', 0);

        // "Fried Momos" and "fried momos " are one section.
        $this->assertSame(1, $business->productCategories()->whereRaw('LOWER(TRIM(name)) = ?', ['fried momos'])->count());
        $this->assertSame(4, $business->productCategories()->count());

        // Scanning the same menu again: every dish is now "already on menu".
        $this->as($owner)->postJson('/api/v1/business/menu-scan/import', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 7)
            ->assertJsonCount(7, 'data.skippedNames');
    }

    public function test_off_limit_and_bad_files(): void
    {
        [$owner] = $this->shop();
        $this->fakeGemini([['name' => 'Tea', 'price' => 10]]);

        // AI switched off.
        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertStatus(503);

        // Not a photo or PDF.
        $this->aiOn(limit: 1);
        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [UploadedFile::fake()->create('menu.docx', 20, 'application/msword')]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Http::assertNothingSent();

        // Monthly cap.
        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])->assertOk();
        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files', 'errors');
    }

    public function test_nothing_readable_is_an_error_and_not_counted(): void
    {
        $this->aiOn();
        [$owner] = $this->shop();
        $this->fakeGemini([]);

        $this->as($owner)->post('/api/v1/business/menu-scan', ['files' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertStatus(502);
        $this->as($owner)->getJson('/api/v1/business/menu-scan')->assertJsonPath('data.used', 0);
    }
}
