<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference table of business categories (document/phase/10 §business_categories,
 * phase/02 §Business Categories). Kept data-driven so new categories never
 * require a schema change ("unlimited categories").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable(); // lucide icon name
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_categories');
    }
};
