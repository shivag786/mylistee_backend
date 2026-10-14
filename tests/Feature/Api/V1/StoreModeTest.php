<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Premium store mode is a plan feature, reported on the public shop page. */
class StoreModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_mode_follows_the_plan_feature(): void
    {
        $business = Business::factory()->create();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.storeMode', false);

        Plan::factory()->create(['is_default' => true, 'features' => ['store_mode']]);

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertJsonPath('data.business.storeMode', true);
    }
}
