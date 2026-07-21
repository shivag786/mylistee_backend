<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Offer;
use App\Models\User;
use App\Services\AiOfferSuggestionService;
use App\Services\OfferSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function foodBusiness(): Business
    {
        $category = BusinessCategory::factory()->create(['name' => 'Cafe & Restaurant']);

        return Business::factory()->create(['category_id' => $category->id]);
    }

    public function test_templates_are_category_specific(): void
    {
        $business = $this->foodBusiness();

        $titles = collect(app(OfferSuggestionService::class)->templates($business))
            ->pluck('title')->all();

        $this->assertContains('Buy One Get One Free', $titles);
    }

    public function test_nudge_fires_when_there_are_no_live_offers(): void
    {
        $business = $this->foodBusiness();

        $nudges = collect(app(OfferSuggestionService::class)->analyticsNudges($business))
            ->pluck('title')->all();

        $this->assertContains('Launch your first reward', $nudges);
    }

    public function test_ai_layer_is_a_noop_without_a_key(): void
    {
        config()->set('services.anthropic.key', null);
        $business = $this->foodBusiness();

        $this->assertFalse(app(AiOfferSuggestionService::class)->isConfigured());
        $this->assertSame([], app(AiOfferSuggestionService::class)->suggest($business));
    }

    public function test_suggestions_endpoint_returns_ideas(): void
    {
        config()->set('services.anthropic.key', null);
        $business = $this->foodBusiness();
        Offer::factory()->create(['business_id' => $business->id]); // has a live offer
        $token = $business->owner->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/business/offers/suggestions')
            ->assertOk()
            ->assertJsonPath('data.aiEnabled', false)
            ->assertJsonStructure(['data' => ['suggestions' => [['title', 'type', 'rewardValue', 'reason', 'source']]]]);

        $this->assertNotEmpty($response->json('data.suggestions'));
    }

    public function test_suggestions_require_owner_role(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/business/offers/suggestions')->assertStatus(403);
    }
}
