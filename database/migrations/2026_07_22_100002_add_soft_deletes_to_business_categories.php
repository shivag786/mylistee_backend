<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 — soft-delete categories so admin removals preserve history and
 * don't orphan businesses (matches the soft-delete convention used across
 * offers/businesses/rewards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_categories', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('business_categories', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
