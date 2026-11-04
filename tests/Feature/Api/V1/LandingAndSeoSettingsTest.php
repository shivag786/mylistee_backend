<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page switch (shop feed or landing page), the landing page's content
 * and the SEO tags, as the admin saves them and as GET /config serves them.
 */
class LandingAndSeoSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        return $this->withToken($admin->createToken('api')->plainTextToken);
    }

    public function test_config_defaults_to_the_shop_feed_with_built_in_content(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.homePage', 'shops')
            ->assertJsonPath('data.landingPage', null)
            ->assertJsonPath('data.seo', null)
            ->assertJsonPath('data.personalLink.trialDays', 7);
    }

    public function test_admin_switches_home_to_the_landing_page_and_edits_it(): void
    {
        $this->asAdmin()->putJson('/api/v1/admin/settings', [
            'homePage' => 'landing',
            'landingPage' => [
                'sections' => [['id' => 'hero', 'enabled' => true], ['id' => 'pricing', 'enabled' => false, 'title' => 'Plans']],
                'hero' => ['title' => 'Apni dukaan online', 'subtitle' => 'Free to start'],
                'faqs' => [['q' => 'Is it free?', 'a' => 'Yes, to start.']],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.homePage', 'landing')
            ->assertJsonPath('data.landingPage.hero.title', 'Apni dukaan online')
            ->assertJsonPath('data.landingPage.sections.1.enabled', false)
            ->assertJsonPath('data.landingPage.faqs.0.a', 'Yes, to start.');
    }

    public function test_landing_page_rejects_unknown_sections_and_home_values(): void
    {
        $this->asAdmin()->putJson('/api/v1/admin/settings', [
            'homePage' => 'somewhere',
            'landingPage' => ['sections' => [['id' => 'nope', 'enabled' => true]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['homePage', 'landingPage.sections.0.id']);
    }

    public function test_admin_saves_seo_and_unknown_pages_are_dropped(): void
    {
        $this->asAdmin()->putJson('/api/v1/admin/settings', [
            'seo' => [
                'siteTitle' => 'Listee — local shops, offers and rewards',
                'description' => 'Find shops near you.',
                'googleVerification' => 'abc_DEF-123',
                'googleAnalyticsId' => 'G-AB12CD34',
                'pages' => ['landing' => ['title' => 'Join Listee'], 'hacker' => ['title' => 'x']],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.seo.googleVerification', 'abc_DEF-123')
            ->assertJsonPath('data.seo.pages.landing.title', 'Join Listee')
            ->assertJsonMissingPath('data.seo.pages.hacker');
    }

    public function test_seo_codes_that_could_break_out_of_the_head_are_refused(): void
    {
        $this->asAdmin()->putJson('/api/v1/admin/settings', [
            'seo' => [
                'googleVerification' => '"><script>alert(1)</script>',
                'googleAnalyticsId' => 'UA-123',
                'ogImageUrl' => 'javascript:alert(1)',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'seo.googleVerification', 'seo.googleAnalyticsId', 'seo.ogImageUrl',
        ]);
    }
}
