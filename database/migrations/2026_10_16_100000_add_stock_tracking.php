<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional stock counts. A product with a count loses one per unit sold —
 * on its own or inside a combo — and goes out of stock at zero. The order
 * remembers what it took, so a cancellation puts exactly that back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Null = stock isn't counted for this product.
            $table->unsignedInteger('stock_qty')->nullable()->after('in_stock');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // {product_id: quantity} taken from stock by this order.
            $table->json('stock_deducted')->nullable()->after('coins_earned');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('stock_qty');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('stock_deducted');
        });
    }
};
