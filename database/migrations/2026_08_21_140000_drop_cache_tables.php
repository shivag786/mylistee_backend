<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the database cache tables.
 *
 * Application caching is off (config/cache.php uses the `null` store), so these
 * tables were dead weight — and a stale row in them could only ever serve
 * out-of-date data. Reads now go straight to the source.
 *
 * Rate limiting is unaffected: it counts in the separate store named by
 * `cache.limiter`, which is the file driver and needs no table.
 *
 * `down()` recreates them exactly as Laravel's stock migration does, so
 * switching back to CACHE_STORE=database is a rollback away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }

    public function down(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }
};
