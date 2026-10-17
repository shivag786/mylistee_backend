<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sizes, add-ons and a product-level discount, kept as one small JSON document
 * per product — a menu item has a handful of each, never queried on their own.
 * Order lines snapshot what the customer picked, like their name and price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('options')->nullable()->after('selling_price');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('options')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('options');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('options');
        });
    }
};
