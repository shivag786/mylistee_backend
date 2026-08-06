<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-business service configuration (hasOne, mirroring loyalty_programs). Says
 * which fulfilment modes the shop offers and its flat delivery fee. Absent row ⇒
 * pickup-only, so every existing business keeps its current behaviour untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_service_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            // Enabled ServiceType values, e.g. ["pickup","dine_in","takeaway"].
            $table->json('modes');
            $table->string('default_mode', 16)->default('pickup');
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_service_settings');
    }
};
