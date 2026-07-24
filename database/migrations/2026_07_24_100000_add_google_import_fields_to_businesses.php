<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-011 — Business Import Engine. Adds the Google Business Profile fields to
 * the EXISTING businesses table (no new business table). All additive & nullable
 * so existing rows and the current Business CRUD keep working untouched.
 *
 * `owner_id` becomes nullable so an admin can pre-seed an *unclaimed* listing
 * imported from Google (later claimed by an owner). Existing businesses keep
 * their owner; the FK + cascade-on-delete are preserved.
 *
 * Image policy: only the Google image URL/reference is stored — files are never
 * downloaded. Display priority is owner image → google URL → placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('google_business_url', 512)->nullable()->after('website');
            $table->string('google_place_id')->nullable()->after('google_business_url');
            $table->decimal('google_rating', 3, 2)->nullable()->after('google_place_id');
            $table->unsignedInteger('google_review_count')->nullable()->after('google_rating');
            $table->string('google_primary_image_url', 1024)->nullable()->after('google_review_count');
            $table->string('google_secondary_image_url', 1024)->nullable()->after('google_primary_image_url');
            $table->string('google_category')->nullable()->after('google_secondary_image_url');
            $table->timestamp('google_imported_at')->nullable()->after('google_category');
            $table->timestamp('google_last_sync')->nullable()->after('google_imported_at');
            $table->string('google_sync_status', 32)->nullable()->after('google_last_sync');

            // Fast duplicate detection on re-import.
            $table->index('google_place_id');
        });

        // owner_id → nullable (admin-imported listings may be unclaimed).
        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['google_place_id']);
            $table->dropColumn([
                'google_business_url',
                'google_place_id',
                'google_rating',
                'google_review_count',
                'google_primary_image_url',
                'google_secondary_image_url',
                'google_category',
                'google_imported_at',
                'google_last_sync',
                'google_sync_status',
            ]);
        });

        // Note: owner_id is left nullable on rollback — reverting to NOT NULL
        // could fail if unclaimed listings exist. Safe and non-destructive.
    }
};
