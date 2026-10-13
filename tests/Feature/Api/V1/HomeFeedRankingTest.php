<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Combo;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home combo row is ranked per viewer: followed shops first, then nearby,
 * and no single shop fills the row.
 */
class HomeFeedRankingTest extends TestCase
{
    use RefreshDatabase;

    /** A combo of two products so it has a real saving and items. */
    private function comboAt(Business $business, int $price = 100): Combo
    {
        $combo = Combo::factory()->create(['business_id' => $business->id, 'combo_price' => $price]);
        foreach (range(1, 2) as $i) {
            $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 80, 'mrp' => 80]);
            $combo->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        }

        return $combo;
    }

    public function test_one_shop_cannot_fill_the_combo_row(): void
    {
        $busy = Business::factory()->create();
        foreach (range(1, 6) as $i) {
            $this->comboAt($busy);
        }
        $other = Business::factory()->create();
        $this->comboAt($other);

        $shops = collect($this->getJson('/api/v1/combos')->assertOk()->json('data'))->pluck('business.slug');

        $this->assertSame(2, $shops->filter(fn ($s) => $s === $busy->slug)->count());
        $this->assertContains($other->slug, $shops);
        // The same shop never sits twice in a row while another shop can go between.
        $this->assertNotSame($shops[0], $shops[1]);
    }

    public function test_a_followed_shop_comes_first(): void
    {
        $customer = User::factory()->create();
        $followed = Business::factory()->create();
        $stranger = Business::factory()->create();
        // The stranger's combo is the better deal — following still wins.
        $this->comboAt($stranger, 40);
        $this->comboAt($followed, 150);
        Favorite::create(['customer_id' => $customer->id, 'business_id' => $followed->id]);

        $this->withToken($customer->createToken('api')->plainTextToken)
            ->getJson('/api/v1/combos')
            ->assertOk()
            ->assertJsonPath('data.0.business.slug', $followed->slug)
            ->assertJsonPath('data.0.business.isFollowed', true);
    }

    public function test_nearby_shops_rank_above_far_ones_and_report_distance(): void
    {
        $far = Business::factory()->create(['latitude' => 28.70, 'longitude' => 77.10]); // Delhi
        $near = Business::factory()->create(['latitude' => 22.7200, 'longitude' => 75.8600]); // Indore
        $this->comboAt($far);
        $this->comboAt($near);

        $response = $this->getJson('/api/v1/combos?lat=22.7196&lng=75.8577')->assertOk();

        $response->assertJsonPath('data.0.business.slug', $near->slug);
        $this->assertLessThan(1000, $response->json('data.0.business.distanceMeters'));
    }

    public function test_bad_coordinates_are_ignored_not_an_error(): void
    {
        $this->comboAt(Business::factory()->create(['latitude' => 22.72, 'longitude' => 75.86]));

        $this->getJson('/api/v1/combos?lat=999&lng=abc')
            ->assertOk()
            ->assertJsonPath('data.0.business.distanceMeters', null);
    }
}
