<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2 — per-business menu sections (e.g. "Burgers", "Beverages"). These are
 * the owner's own menu groups, distinct from the platform-wide business
 * categories (Phase 7.1). They group the menu (7.4) and drive the combo
 * builder's "choose category" step (7.3). Created on the fly from the product
 * form, so management stays lightweight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
