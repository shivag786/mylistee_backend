<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ShopReel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Shop Reels: the owner posts, hides and deletes; customers see a ranked feed. */
class ShopReelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @return array{0: User, 1: Business} */
    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_owner_posts_a_video_reel_linked_to_a_dish(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $dish = Product::factory()->create(['business_id' => $business->id, 'name' => 'Masala Dosa']);

        $this->withToken($this->token($owner))
            ->post('/api/v1/business/reels', [
                'type' => 'video',
                'video' => UploadedFile::fake()->create('dosa.mp4', 2048, 'video/mp4'),
                'poster' => UploadedFile::fake()->image('poster.jpg', 360, 640),
                'caption' => 'Crispy every time',
                'productId' => $dish->uuid,
                'durationSeconds' => 12,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.dish.name', 'Masala Dosa')
            ->assertJsonPath('data.caption', 'Crispy every time');

        $reel = ShopReel::first();
        Storage::disk('public')->assertExists($reel->video_path);
        Storage::disk('public')->assertExists($reel->poster_path);
    }

    public function test_owner_posts_a_photo_reel(): void
    {
        [$owner] = $this->ownerWithBusiness();

        $this->withToken($this->token($owner))
            ->post('/api/v1/business/reels', [
                'type' => 'photos',
                'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.photoUrls');
    }

    public function test_videos_over_the_size_cap_and_foreign_dishes_are_refused(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $foreign = Product::factory()->create();

        $this->withToken($this->token($owner))
            ->post('/api/v1/business/reels', [
                'type' => 'video',
                'video' => UploadedFile::fake()->create('big.mp4', 30000, 'video/mp4'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->withToken($this->token($owner))
            ->post('/api/v1/business/reels', [
                'type' => 'photos',
                'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
                'productId' => $foreign->uuid,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_hidden_reels_leave_the_public_feed(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $reel = ShopReel::create(['business_id' => $business->id, 'type' => 'photos', 'photo_paths' => ['reels/photos/a.webp', 'reels/photos/b.webp']]);

        $this->getJson('/api/v1/reels')->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($this->token($owner))
            ->patchJson("/api/v1/business/reels/{$reel->uuid}/visibility", ['visible' => false])
            ->assertOk();

        $this->getJson('/api/v1/reels')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_owner_cannot_touch_another_shops_reel(): void
    {
        [$owner] = $this->ownerWithBusiness();
        $other = ShopReel::create(['business_id' => Business::factory()->create()->id, 'type' => 'photos', 'photo_paths' => []]);

        $this->withToken($this->token($owner))->deleteJson("/api/v1/business/reels/{$other->uuid}")->assertNotFound();
        $this->assertNotNull($other->fresh());
    }

    public function test_a_view_counts_once_per_address_per_hour(): void
    {
        $reel = ShopReel::create(['business_id' => Business::factory()->create()->id, 'type' => 'photos', 'photo_paths' => []]);

        $this->postJson("/api/v1/reels/{$reel->uuid}/view")->assertOk();
        $this->postJson("/api/v1/reels/{$reel->uuid}/view")->assertOk();

        $this->assertSame(1, $reel->fresh()->views);
    }
}
