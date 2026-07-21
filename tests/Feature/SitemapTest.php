<?php

namespace Tests\Feature;

use App\Enums\BusinessStatus;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_active_businesses_only(): void
    {
        Business::factory()->create(['slug' => 'chai-nagri', 'status' => BusinessStatus::Active]);
        Business::factory()->create(['slug' => 'hidden-shop', 'status' => BusinessStatus::Pending]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('<urlset', $content);
        $this->assertStringContainsString('/b/chai-nagri', $content);
        $this->assertStringNotContainsString('/b/hidden-shop', $content);
    }
}
