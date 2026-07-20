<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every spin a customer makes (document/phase/10 §spin_history). Used to enforce
 * the one-spin-per-business-per-day limit (phase/02 §Spin Limit) and, later,
 * fraud detection / analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spins', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained('rewards')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('device')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spins');
    }
};
