<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pages Google Play and payment providers ask for — Privacy, Terms,
 * Refund, Delete account — plus About, Contact and FAQ, as CMS pages the
 * admin edits. Content comes from database/content/pages/*.md.
 *
 * Fills a page only when it is missing or still holds the seeder's
 * placeholder, so nothing an admin wrote is ever overwritten.
 */
return new class extends Migration
{
    private const PAGES = [
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms & Conditions',
        'refund' => 'Refund & Cancellation Policy',
        'delete-account' => 'Delete your account',
        'about' => 'About Listee',
        'contact' => 'Contact us',
        'faq' => 'FAQs',
    ];

    public function up(): void
    {
        foreach (self::PAGES as $slug => $title) {
            $file = database_path("content/pages/{$slug}.md");
            if (! is_file($file)) {
                continue;
            }
            $body = trim((string) file_get_contents($file));
            $row = DB::table('cms_pages')->where('slug', $slug)->first();

            if ($row === null) {
                DB::table('cms_pages')->insert([
                    'uuid' => (string) Str::uuid(),
                    'slug' => $slug,
                    'title' => $title,
                    'body' => $body,
                    'status' => 'published',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif (blank($row->body) || str_contains((string) $row->body, 'Edit this page in the admin panel')
                || mb_strlen((string) $row->body) < 120) {
                DB::table('cms_pages')->where('id', $row->id)->update([
                    'title' => $title,
                    'body' => $body,
                    'status' => 'published',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Content only; nothing to undo.
    }
};
