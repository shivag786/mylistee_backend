<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The home hero dishes and shop stories. */
class HomeSpotlightTest extends TestCase
{
    use RefreshDatabase;

    private function dish(Business $b, array $attrs = []): Product
    {
        return Product::factory()->create([
            'business_id' => $b->id,
            'image_path' => 'products/x.jpg',
            ...$attrs,
        ]);
    }

    public function test_hero_shows_photographed_dishes_and_caps_each_shop(): void
    {
        $busy = Business::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->dish($busy, ['is_bestseller' => true]);
        }
        $this->dish($busy, ['image_path' => null, 'is_todays_special' => true]);
        $other = Business::factory()->create();
        $this->dish($other);

        $hero = collect($this->getJson('/api/v1/home/spotlight')->assertOk()->json('data.hero'));

        $this->assertSame(2, $hero->where('business.slug', $busy->slug)->count());
        $this->assertTrue($hero->every(fn ($d) => $d['imageUrl'] !== null));
        $this->assertContains($other->slug, $hero->pluck('business.slug'));
    }

    public function test_stories_hold_followed_shops_and_shops_with_news(): void
    {
        $customer = User::factory()->create();
        $followed = Business::factory()->create();
        Favorite::create(['customer_id' => $customer->id, 'business_id' => $followed->id]);
        $special = Business::factory()->create();
        $this->dish($special, ['is_todays_special' => true, 'name' => 'Paneer roll']);
        $quiet = Business::factory()->create();
        $this->dish($quiet);

        $stories = collect(
            $this->withToken($customer->createToken('api')->plainTextToken)
                ->getJson('/api/v1/home/spotlight')
                ->assertOk()
                ->json('data.stories'),
        );

        $this->assertSame($followed->slug, $stories->first()['slug']);
        $this->assertTrue($stories->first()['isFollowed']);
        $this->assertSame("Today's special: Paneer roll", $stories->firstWhere('slug', $special->slug)['headline']);
        $this->assertNull($stories->firstWhere('slug', $quiet->slug));
    }
}
