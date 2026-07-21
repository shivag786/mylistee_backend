<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reward tiers a customer can redeem Listee Coins for (Phase 2). Each tier is
 * owned by a business ("150 coins → free coffee"). Redeeming one mints a normal
 * reward code the owner scans through the existing redemption flow (slice 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('title');
            $table->string('description')->nullable();
            $table->unsignedInteger('coins_cost');
            $table->string('reward_value')->nullable(); // e.g. "1 Free Coffee", "20% off"
            $table->boolean('active')->default(true);
            $table->unsignedInteger('stock')->nullable(); // null = unlimited
            $table->unsignedInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
    }
};
