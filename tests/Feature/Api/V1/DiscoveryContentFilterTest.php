<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Combo;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC (homepage): discovery rows hide "empty" shops — a shop only appears with
 * a live offer, a visible product, or a visible combo.
 */
class DiscoveryContentFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_content_hides_empty_shops(): void
    {
        // Empty shop — no offers, products or combos.
        Business::factory()->create(['name' => 'Empty Shop', 'status' => 'active']);

        // Shop with a visible product qualifies.
        $withProduct = Business::factory()->create(['name' => 'Has Product', 'status' => 'active']);
        Product::factory()->create(['business_id' => $withProduct->id, 'is_visible' => true]);

        // Shop with a visible combo qualifies.
        $withCombo = Business::factory()->create(['name' => 'Has Combo', 'status' => 'active']);
        Combo::factory()->create(['business_id' => $withCombo->id, 'is_visible' => true]);

        $names = collect($this->getJson('/api/v1/businesses?withContent=1')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Has Product', $names);
        $this->assertContains('Has Combo', $names);
        $this->assertNotContains('Empty Shop', $names);
    }

    public function test_without_filter_still_returns_all_active_shops(): void
    {
        Business::factory()->create(['name' => 'Empty Shop', 'status' => 'active']);

        $names = collect($this->getJson('/api/v1/businesses')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Empty Shop', $names, 'Plain browsing should not hide shops.');
    }
}
