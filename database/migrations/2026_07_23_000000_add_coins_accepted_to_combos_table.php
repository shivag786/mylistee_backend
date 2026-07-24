<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Max Listee coins a customer may spend on a combo (owner-set). Null / 0 means
 * the combo doesn't accept coins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combos', function (Blueprint $table): void {
            $table->unsignedInteger('coins_accepted')->nullable()->after('wallet_coins_accepted');
        });
    }

    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table): void {
            $table->dropColumn('coins_accepted');
        });
    }
};
