<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional business images (document/phase/10 §business_gallery, phase/07 §Gallery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_gallery', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('image_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_gallery');
    }
};
