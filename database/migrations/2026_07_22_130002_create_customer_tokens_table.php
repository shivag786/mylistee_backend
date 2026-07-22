<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.3 — the rotating wallet token a customer shows at the counter so the
 * owner can look them up (and redeem wallet coins) without a phone number. A
 * short numeric code, valid 30 minutes, regenerated on expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 8)->index();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tokens');
    }
};
