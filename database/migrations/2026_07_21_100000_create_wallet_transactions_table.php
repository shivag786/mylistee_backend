<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listee Coins ledger (Phase 2 — flagship). An append-only record of every coin
 * movement; the customer's balance is the SUM of `amount` (never a denormalized
 * column, so it cannot drift — same principle as the reward wallet, M7).
 *
 * `business_id` is nullable: platform-level grants (e.g. the welcome bonus) have
 * no business. `balance_after` snapshots the running total at write time for a
 * fast, auditable history view. `reference_*` optionally links the entry to the
 * spin / reward / review that caused it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();

            $table->string('type', 16);   // CoinTransactionType
            $table->string('source', 32); // CoinSource
            $table->integer('amount');    // signed: +earn / -spend
            $table->integer('balance_after');
            $table->string('description')->nullable();

            $table->nullableMorphs('reference'); // reference_type + reference_id
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
