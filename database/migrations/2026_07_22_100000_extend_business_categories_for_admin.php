<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 — Master Category Management. Adds the admin-managed presentation
 * fields to the existing categories table (image + circle-cropped preview,
 * description, SEO alt text, and homepage/search visibility toggles). Additive
 * only — existing category consumers keep working. `sort_order` already stores
 * the display position and `slug` the SEO slug, so those are reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_categories', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('icon');
            $table->text('description')->nullable()->after('image_path');
            $table->string('alt_text')->nullable()->after('description');
            $table->boolean('show_on_homepage')->default(true)->after('alt_text');
            $table->boolean('show_in_search')->default(true)->after('show_on_homepage');
        });
    }

    public function down(): void
    {
        Schema::table('business_categories', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'description',
                'alt_text',
                'show_on_homepage',
                'show_in_search',
            ]);
        });
    }
};
