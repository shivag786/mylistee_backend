<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

/**
 * Default CMS pages (document/phase/14 §CMS Management). Placeholder copy the
 * admin edits in the panel. `firstOrCreate` so edits survive a re-seed.
 */
class CmsPageSeeder extends Seeder
{
    /** @var list<array{slug: string, title: string, body: string}> */
    private const PAGES = [
        ['slug' => 'about', 'title' => 'About Us', 'body' => 'Listee helps local businesses reward and keep their customers.'],
        ['slug' => 'privacy', 'title' => 'Privacy Policy', 'body' => 'Your privacy matters. Edit this page in the admin panel.'],
        ['slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => 'Terms of use. Edit this page in the admin panel.'],
        ['slug' => 'faq', 'title' => 'FAQs', 'body' => 'Frequently asked questions. Edit this page in the admin panel.'],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $page) {
            CmsPage::firstOrCreate(
                ['slug' => $page['slug']],
                ['title' => $page['title'], 'body' => $page['body'], 'status' => 'published'],
            );
        }
    }
}
