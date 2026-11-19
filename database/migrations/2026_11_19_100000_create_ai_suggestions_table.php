<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggestions from the AI Sales Manager (kind = sales) and the AI Customer
 * Growth Advisor (kind = growth). The owner approves or dismisses each one;
 * approving creates real promotions / combos / coupons and, for growth
 * campaigns, the push notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);            // sales | growth
            $table->uuid('batch');                 // one AI run
            $table->string('type', 32);            // percent, combo, win_back, …
            $table->string('title', 160);
            $table->text('message')->nullable();   // customer-facing text (Hinglish)
            $table->text('reasoning')->nullable();
            $table->json('payload')->nullable();   // products, value, window, segment, …
            $table->json('stats')->nullable();     // the numbers it was based on
            $table->string('status', 16)->default('pending'); // pending | approved | dismissed | expired
            $table->json('result')->nullable();    // what approving created
            $table->timestamp('send_at')->nullable();  // growth: when the push goes out
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'kind', 'status']);
            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
