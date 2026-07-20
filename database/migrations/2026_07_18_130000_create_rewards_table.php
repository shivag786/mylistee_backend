<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rewards won on the spinner — the contents of a customer's wallet
 * (document/phase/02 §Wallet, phase/10 §wallets). Offer details are snapshotted
 * (title/value/type) so the reward survives edits or deletion of the offer.
 * `code` is what the business scans/enters to redeem (Milestone 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();

            $table->string('code', 16)->unique();
            $table->string('title');
            $table->string('reward_value')->nullable();
            $table->string('type', 32)->nullable();

            $table->string('status', 32)->default('active')->index();
            $table->timestamp('won_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
