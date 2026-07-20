<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer favorites + reviews (document/phase/10 §favorite_businesses, §reviews).
 * One favorite and one review per customer per business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_businesses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'business_id']);
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('status', 32)->default('published')->index();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('favorite_businesses');
    }
};
