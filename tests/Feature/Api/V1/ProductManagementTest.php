<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithBusiness(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function actingAsToken(User $user): static
    {
        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_owner_can_create_a_product_and_menu_section_on_the_fly(): void
    {
        Storage::fake('public');
        [$owner, $business] = $this->ownerWithBusiness();

        $this->actingAsToken($owner)
            ->post('/api/v1/business/products', [
                'name' => 'Cheese Burger',
                'category_name' => 'Burgers',
                'mrp' => 200,
                'selling_price' => 160,
                'food_type' => 'non_veg',
                'is_bestseller' => '1',
                'image' => UploadedFile::fake()->image('burger.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cheese Burger')
            ->assertJsonPath('data.categoryName', 'Burgers')
            ->assertJsonPath('data.discountPercent', 20)
            ->assertJsonPath('data.isBestseller', true);

        $this->assertDatabaseHas('product_categories', ['business_id' => $business->id, 'name' => 'Burgers']);
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'name' => 'Cheese Burger']);
        $product = Product::first();
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_owner_can_list_update_and_delete_products(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Old']);

        $this->actingAsToken($owner)
            ->getJson('/api/v1/business/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->uuid);

        $this->actingAsToken($owner)
            ->post("/api/v1/business/products/{$product->uuid}", [
                'name' => 'New Name',
                'selling_price' => 99,
                '_method' => 'PUT',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.sellingPrice', 99);

        $this->actingAsToken($owner)
            ->deleteJson("/api/v1/business/products/{$product->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_owner_can_toggle_a_flag_and_reorder(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();
        $a = Product::factory()->create(['business_id' => $business->id, 'position' => 1, 'is_visible' => true]);
        $b = Product::factory()->create(['business_id' => $business->id, 'position' => 2]);

        $this->actingAsToken($owner)
            ->patchJson("/api/v1/business/products/{$a->uuid}/toggle", ['field' => 'is_visible', 'value' => false])
            ->assertOk()
            ->assertJsonPath('data.isVisible', false);

        $this->actingAsToken($owner)
            ->patchJson('/api/v1/business/products/reorder', ['order' => [$b->uuid, $a->uuid]])
            ->assertOk();

        $this->assertSame(1, $b->fresh()->position);
        $this->assertSame(2, $a->fresh()->position);
    }

    public function test_owner_can_manage_gallery_images(): void
    {
        Storage::fake('public');
        [$owner, $business] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $add = $this->actingAsToken($owner)
            ->post("/api/v1/business/products/{$product->uuid}/images", [
                'image' => UploadedFile::fake()->image('extra.jpg'),
            ])
            ->assertCreated();

        $imageId = $add->json('meta.imageId');
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id]);

        $this->actingAsToken($owner)
            ->deleteJson("/api/v1/business/products/{$product->uuid}/images/{$imageId}")
            ->assertOk();

        $this->assertDatabaseMissing('product_images', ['uuid' => $imageId]);
    }

    public function test_menu_sections_crud(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/product-categories', ['name' => 'Beverages'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Beverages');

        $this->actingAsToken($owner)
            ->getJson('/api/v1/business/product-categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beverages');

        $this->assertDatabaseHas('product_categories', ['business_id' => $business->id, 'name' => 'Beverages']);
    }

    public function test_products_are_scoped_to_the_owning_business(): void
    {
        [, $businessA] = $this->ownerWithBusiness();
        [$ownerB] = $this->ownerWithBusiness();
        $product = Product::factory()->create(['business_id' => $businessA->id]);

        // Owner B cannot see or mutate owner A's product.
        $this->actingAsToken($ownerB)
            ->getJson('/api/v1/business/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsToken($ownerB)
            ->deleteJson("/api/v1/business/products/{$product->uuid}")
            ->assertStatus(404);
    }

    public function test_creating_a_product_requires_a_business(): void
    {
        $owner = User::factory()->businessOwner()->create();

        $this->actingAsToken($owner)
            ->postJson('/api/v1/business/products', ['name' => 'X', 'selling_price' => 10])
            ->assertStatus(404);
    }
}
