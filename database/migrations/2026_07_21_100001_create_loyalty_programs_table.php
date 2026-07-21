<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-business loyalty configuration (Phase 2). One row per business; each earn
 * rate is nullable and, when null, falls back to the platform default in
 * config/loyalty.php — so a business works out-of-the-box and only stores the
 * rates it has deliberately overridden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();

            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('coins_per_spin')->nullable();
            $table->unsignedInteger('coins_per_first_scan')->nullable();
            $table->unsignedInteger('coins_per_checkin')->nullable();
            $table->unsignedInteger('coins_per_review')->nullable();
            $table->unsignedInteger('coins_per_redeem')->nullable();
            $table->unsignedInteger('monthly_budget_cap')->nullable(); // null/0 = unlimited

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
