<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Searching a dish ("momos") finds the shops that sell it, with the matches. */
class DishSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_dish_names_and_returns_them(): void
    {
        $momoShop = Business::factory()->create(['name' => 'Hill Cafe']);
        Product::factory()->create(['business_id' => $momoShop->id, 'name' => 'Paneer Momos']);
        Product::factory()->create(['business_id' => $momoShop->id, 'name' => 'Hidden Momos', 'is_visible' => false]);
        Product::factory()->create(['business_id' => $momoShop->id, 'name' => 'Cold Coffee']);
        Business::factory()->create(['name' => 'Cake House']);

        $rows = collect($this->getJson('/api/v1/businesses?search=momos')->assertOk()->json('data'));

        $this->assertSame([$momoShop->slug], $rows->pluck('slug')->all());
        $this->assertSame(['Paneer Momos'], collect($rows[0]['matchedItems'])->pluck('name')->all());
    }

    public function test_plain_listing_has_no_matched_items(): void
    {
        Business::factory()->create();

        $row = $this->getJson('/api/v1/businesses')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('matchedItems', $row);
    }
}
