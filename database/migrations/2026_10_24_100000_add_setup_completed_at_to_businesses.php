<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop setup from the vendor app. An owner can start a shop with just a name
 * and category (to add products right away) and finish the address and hours
 * later. Until then the shop is `pending` — hidden from customers like any
 * pending shop — and `setup_completed_at` is null.
 *
 * Every existing shop is already set up, so they are all stamped now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->timestamp('setup_completed_at')->nullable();
        });

        DB::table('businesses')->whereNull('setup_completed_at')->update([
            'setup_completed_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('setup_completed_at');
        });
    }
};
