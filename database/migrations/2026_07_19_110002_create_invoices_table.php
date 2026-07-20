<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing invoices (document/phase/14 §Payment Management — placeholder). No real
 * gateway in v1: an upgrade records a `paid` invoice immediately. Plan name +
 * amount are snapshotted so the record is stable if the plan changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique(); // INV-2026-000001
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('plan_name');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 16)->default('paid'); // paid / pending / failed / refunded

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
