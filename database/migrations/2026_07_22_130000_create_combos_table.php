<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.3 — Combo Builder. A combo bundles 2–3 products at a special price.
 * Total MRP and savings are derived from the member products (via combo_items),
 * so only `combo_price` and the reward config are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('product_category_id')->nullable()
                ->constrained('product_categories')->nullOnDelete();

            $table->string('name');
            $table->string('image_path')->nullable();
            $table->decimal('combo_price', 10, 2);

            // Optional loyalty / reward sweeteners (PHASE 7.3 §Combo Fields).
            $table->unsignedInteger('coins_earned')->nullable();
            $table->boolean('wallet_coins_accepted')->default(false);
            $table->string('next_visit_coupon')->nullable();
            $table->string('bonus_reward')->nullable();

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('auto_enable')->default(true);
            $table->boolean('auto_disable')->default(true);

            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combos');
    }
};
