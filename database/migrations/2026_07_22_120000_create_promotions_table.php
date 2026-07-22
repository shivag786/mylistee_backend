<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2b — the Master Promotion Engine (07A). ONE table for every
 * promotion type; type-specific fields live in `config` (JSON) so new types
 * never need a schema change. A promotion may target a single product
 * (`product_id`, a "Smart Offer") or the whole business (null). Combos (7.3)
 * add their own structure and reference this engine for scheduling.
 *
 * The separate `offers` table (the spinner reward pool) is intentionally left
 * untouched — it is a different concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            $table->string('promotion_type', 32);
            $table->string('name');
            $table->json('config')->nullable();

            $table->string('status', 32)->default('draft')->index();

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->time('daily_start_time')->nullable(); // happy-hour window
            $table->time('daily_end_time')->nullable();

            $table->boolean('auto_start')->default(true);
            $table->boolean('auto_stop')->default(true);
            $table->unsignedInteger('priority')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
