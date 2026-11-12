<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - orders.serial: the order's number for the day at its shop (1, 2, 3…),
 *   shown next to the token so staff can call "order 12".
 * - businesses.online_pay_hidden: the admin hides "Pay online" for one shop;
 *   its customers then only see pay at the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'serial')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedSmallInteger('serial')->nullable()->after('token');
            });
        }
        if (! Schema::hasColumn('businesses', 'online_pay_hidden')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('online_pay_hidden')->default(false)->after('featured');
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('serial'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('online_pay_hidden'));
    }
};
