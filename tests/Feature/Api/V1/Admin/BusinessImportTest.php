<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessImportLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC-011 — Business Import Engine. Runs against the dev sandbox provider
 * (no Google key in the test env), which returns deterministic preview data.
 */
class BusinessImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.google.com/maps/place/Sunrise+Cafe/@19.1,72.9,17z';

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_import_endpoints_require_admin(): void
    {
        $owner = User::factory()->businessOwner()->create();

        $this->withToken($this->token($owner))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => self::URL])
            ->assertForbidden();
    }

    public function test_invalid_url_is_rejected(): void
    {
        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => 'not-a-url'])
            ->assertStatus(422);
    }

    public function test_preview_returns_normalized_data_without_saving(): void
    {
        $this->withToken($this->token($this->admin()))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('data.preview.name', 'Sunrise Cafe')
            ->assertJsonPath('data.duplicate', null)
            ->assertJsonStructure([
                'data' => ['preview' => ['placeId', 'rating', 'reviewCount', 'primaryImageUrl', 'category']],
            ]);

        // Nothing persisted on preview.
        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('business_import_logs', 0);
    }

    public function test_import_creates_an_unclaimed_listing_and_a_log(): void
    {
        $admin = $this->admin();
        $preview = $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => self::URL])
            ->json('data.preview');

        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import', [
                'url' => $preview['sourceUrl'],
                'placeId' => $preview['placeId'],
                'mode' => 'create',
                'fields' => [
                    'name' => $preview['name'],
                    'phone' => $preview['phone'],
                    'website' => $preview['website'],
                    'address' => $preview['address'],
                    'category' => $preview['category'],
                    'rating' => $preview['rating'],
                    'reviewCount' => $preview['reviewCount'],
                    'primaryImageUrl' => $preview['primaryImageUrl'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'created');

        $business = Business::first();
        $this->assertNotNull($business);
        $this->assertNull($business->owner_id, 'Imported listing should be unclaimed.');
        $this->assertSame($preview['placeId'], $business->google_place_id);
        $this->assertNotNull($business->google_primary_image_url);
        $this->assertNotNull($business->google_imported_at);

        $this->assertDatabaseHas('business_import_logs', [
            'business_id' => $business->id,
            'imported_by' => $admin->id,
            'status' => 'created',
        ]);
    }

    public function test_reimport_detects_duplicate_and_updates(): void
    {
        $admin = $this->admin();

        // Seed an existing business with the same place id.
        $preview = $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => self::URL])
            ->json('data.preview');

        $existing = Business::factory()->create([
            'name' => 'Sunrise Cafe',
            'google_place_id' => $preview['placeId'],
            'phone' => '000',
        ]);

        // Preview again → duplicate surfaced.
        $res = $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import/preview', ['url' => self::URL])
            ->assertOk();
        $this->assertSame($existing->uuid, $res->json('data.duplicate.id'));

        // Update the existing business's phone from the import.
        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import', [
                'url' => $preview['sourceUrl'],
                'placeId' => $preview['placeId'],
                'mode' => 'update',
                'businessId' => $existing->uuid,
                'fields' => ['phone' => $preview['phone']],
            ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'updated');

        $this->assertSame($preview['phone'], $existing->fresh()->phone);
        $this->assertDatabaseCount('businesses', 1); // no new row on update
    }

    public function test_ignore_records_a_log_and_changes_nothing(): void
    {
        $admin = $this->admin();
        $existing = Business::factory()->create(['name' => 'Sunrise Cafe', 'phone' => 'keep-me']);

        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/businesses/import', [
                'url' => self::URL,
                'mode' => 'ignore',
                'businessId' => $existing->uuid,
            ])
            ->assertOk();

        $this->assertSame('keep-me', $existing->fresh()->phone);
        $this->assertDatabaseHas('business_import_logs', ['business_id' => $existing->id, 'status' => 'ignored']);
    }

    public function test_logs_endpoint_lists_import_history(): void
    {
        $admin = $this->admin();
        BusinessImportLog::create([
            'imported_by' => $admin->id,
            'source' => 'google',
            'source_url' => self::URL,
            'status' => 'created',
            'updated_fields' => ['name'],
        ]);

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/businesses/import/logs')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'created');
    }
}
