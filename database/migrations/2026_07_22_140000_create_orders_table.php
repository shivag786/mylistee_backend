<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.5 — customer orders. A customer builds a cart for ONE shop and
 * confirms it into an order, which gets a short `token` the owner reads at the
 * counter. Money totals are snapshotted; wallet coins may be spent (coin_used /
 * coin_discount) and are earned back on payment (coins_earned). Payment is a
 * manual owner toggle (no gateway yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 8)->index();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 32)->default('placed')->index();
            $table->decimal('subtotal', 10, 2);
            $table->unsignedInteger('coins_used')->default(0);
            $table->decimal('coin_discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->unsignedInteger('coins_earned')->default(0);
            $table->text('note')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
