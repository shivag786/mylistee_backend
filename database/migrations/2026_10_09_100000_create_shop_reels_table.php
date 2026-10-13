<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop Reels — short videos (or a few photos played as a reel) a shop posts on
 * Listee itself, shown on the customer home page and in a full-screen reel
 * viewer. Kept on Listee rather than pulled from Instagram: scraping breaks
 * Instagram's terms, and the official API needs Meta app review.
 *
 * A reel may point at one dish (`product_id`) so the viewer can offer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_reels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type', 16); // video | photos
            $table->string('video_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->json('photo_paths')->nullable();
            $table->string('caption', 150)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_reels');
    }
};
