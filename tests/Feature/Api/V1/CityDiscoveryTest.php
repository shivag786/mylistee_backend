<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shops are listed city-wise. The awkward case is the one that matters: the
 * column is new, so shops with no city yet must not disappear from the app.
 */
class CityDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_shops_from_the_requested_city(): void
    {
        Business::factory()->create(['name' => 'Pune Cafe', 'city' => 'Pune', 'status' => 'active']);
        Business::factory()->create(['name' => 'Mumbai Cafe', 'city' => 'Mumbai', 'status' => 'active']);

        $response = $this->getJson('/api/v1/businesses?city=Pune')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Pune Cafe', $names);
        $this->assertNotContains('Mumbai Cafe', $names);
    }

    public function test_a_shop_with_no_city_yet_is_still_listed(): void
    {
        // Every business predates the column. Filtering them out strictly would
        // empty the app until each owner went and edited their profile.
        Business::factory()->create(['name' => 'Unset Cafe', 'city' => null, 'status' => 'active']);
        Business::factory()->create(['name' => 'Mumbai Cafe', 'city' => 'Mumbai', 'status' => 'active']);

        $response = $this->getJson('/api/v1/businesses?city=Pune')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Unset Cafe', $names);
        $this->assertNotContains('Mumbai Cafe', $names);
    }

    public function test_without_a_city_every_shop_is_listed(): void
    {
        Business::factory()->create(['name' => 'Pune Cafe', 'city' => 'Pune', 'status' => 'active']);
        Business::factory()->create(['name' => 'Mumbai Cafe', 'city' => 'Mumbai', 'status' => 'active']);

        $names = array_column($this->getJson('/api/v1/businesses')->assertOk()->json('data'), 'name');

        $this->assertContains('Pune Cafe', $names);
        $this->assertContains('Mumbai Cafe', $names);
    }

    public function test_the_list_reports_each_shops_city(): void
    {
        Business::factory()->create(['name' => 'Pune Cafe', 'city' => 'Pune', 'status' => 'active']);

        $this->getJson('/api/v1/businesses?city=Pune')
            ->assertOk()
            ->assertJsonPath('data.0.city', 'Pune');
    }
}
