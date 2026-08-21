<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razorpay payment attempts for business plan subscriptions.
 *
 * One row per Razorpay *order* — created the moment the owner opens Checkout, so
 * abandoned attempts are recorded too (they simply stay `created`). The row is
 * the audit trail that ties a Listee invoice to a Razorpay dashboard entry.
 *
 * Amounts are stored twice on purpose: `amount` in rupees to match invoices and
 * plans, `amount_paise` exactly as Razorpay saw it, so a reconciliation never
 * depends on our rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('gateway', 32)->default('razorpay');
            // Razorpay ids. order_id is unique — it is our idempotency key for
            // both the verify callback and the webhook, which can race.
            $table->string('gateway_order_id')->unique();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('gateway_signature')->nullable();
            $table->string('receipt')->nullable();

            $table->string('status', 16)->default('created');
            $table->decimal('amount', 10, 2)->default(0);
            $table->unsignedBigInteger('amount_paise')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('method', 32)->nullable();   // upi / card / netbanking / wallet

            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_description')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
