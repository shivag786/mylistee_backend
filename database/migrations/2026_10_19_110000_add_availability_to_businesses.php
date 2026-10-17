<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-controlled availability (Settings page):
 *  - closed_until / closed_note: shop temporarily closed (holiday, repairs…)
 *    until a time; it reopens by itself once that passes;
 *  - orders_paused: shop stays open and visible, but takes no new orders
 *    (kitchen rush, out of stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->timestamp('closed_until')->nullable();
            $table->string('closed_note', 120)->nullable();
            $table->boolean('orders_paused')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['closed_until', 'closed_note', 'orders_paused']);
        });
    }
};
