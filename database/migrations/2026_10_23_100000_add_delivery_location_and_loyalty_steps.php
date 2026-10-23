<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home delivery carries a contact number and, when the customer used their
 * location or the map, the pin — so the shop can call and navigate.
 *
 * Loyalty coupons remember which step (3rd, 5th… order) earned them, and when
 * the customer scratched the card open. Existing coupons stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_phone', 15)->nullable()->after('service_address');
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_phone');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
        });

        Schema::table('coupons', function (Blueprint $table): void {
            $table->unsignedSmallInteger('tier_at')->nullable()->after('kind');
            $table->dateTime('revealed_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_phone', 'delivery_lat', 'delivery_lng']);
        });

        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn(['tier_at', 'revealed_at']);
        });
    }
};
