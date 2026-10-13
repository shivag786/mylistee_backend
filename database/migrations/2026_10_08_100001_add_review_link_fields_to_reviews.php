<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews left through a shop's public review link. Those reviewers have no
 * account, only a mobile number, so:
 *  - customer_id becomes nullable and shop_customer_id identifies them instead
 *    (one review per mobile per shop, edited on resubmit);
 *  - rating becomes nullable: the overall star is optional on the link form;
 *  - hidden_by_owner_at lets the owner take a review off their public page.
 *    Kept apart from `status` (admin moderation) so an owner can never
 *    republish what an admin hid, and the review still counts in the rating.
 * review_items holds optional per-item star ratings, with the item name
 * snapshotted so a later rename or delete doesn't rewrite the review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->change();
            $table->unsignedTinyInteger('rating')->nullable()->change();
            $table->foreignId('shop_customer_id')->nullable()->after('customer_id')
                ->constrained('shop_customers')->nullOnDelete();
            $table->string('reviewer_name', 80)->nullable()->after('shop_customer_id');
            $table->timestamp('hidden_by_owner_at')->nullable()->after('status');

            $table->unique(['business_id', 'shop_customer_id']);
        });

        Schema::create('review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('combo_id')->nullable()->constrained('combos')->nullOnDelete();
            $table->string('item_type', 16); // product | combo
            $table->string('name');
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'shop_customer_id']);
            $table->dropConstrainedForeignId('shop_customer_id');
            $table->dropColumn(['reviewer_name', 'hidden_by_owner_at']);
        });
    }
};
