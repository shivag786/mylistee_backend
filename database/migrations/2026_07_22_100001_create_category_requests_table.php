<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 — Business owners can request a new master category when they don't
 * find a fitting one. Requests are moderated by the admin (pending → approved /
 * rejected). On approval a real `business_categories` row is created and linked
 * back via `created_category_id`, then it is instantly available to everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();

            $table->string('name');
            $table->string('sample_image_path')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_category_id')->nullable()
                ->constrained('business_categories')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_requests');
    }
};
