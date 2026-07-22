<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2 — the business's product catalogue / menu items. Pricing is stored
 * as MRP + selling price; time-bound discounts live in the promotions engine
 * (7.2b), never by editing the price repeatedly (PHASE 7.2 §Discount Module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('product_category_id')->nullable()
                ->constrained('product_categories')->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->text('ingredients')->nullable();
            $table->string('image_path')->nullable();

            $table->decimal('mrp', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2);

            $table->string('food_type', 16)->nullable(); // veg | non_veg | egg
            $table->time('available_from')->nullable();
            $table->time('available_to')->nullable();
            $table->unsignedInteger('prep_minutes')->nullable();

            $table->boolean('is_todays_special')->default(false);
            $table->boolean('is_bestseller')->default(false);
            $table->boolean('is_recommended')->default(false);

            $table->boolean('in_stock')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'is_visible']);
            $table->index(['business_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
