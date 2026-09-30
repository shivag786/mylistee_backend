<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payment for customer orders.
 *
 * Until now every order was settled at the counter and Razorpay only ever saw
 * business plan subscriptions. Three things change:
 *
 *  - A shop chooses how much is taken online (all of it, or a percentage up
 *    front) and whether cash on delivery is still allowed.
 *  - An order records what the customer chose and how the money split: the
 *    online portion, the convenience fee charged on it, and when it landed.
 *  - A payment row can belong to an order instead of a plan, so one table and
 *    one webhook keep the audit trail for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_service_settings', function (Blueprint $table): void {
            // 'full' takes the whole total online; 'partial' takes partial_percent
            // of it up front and leaves the rest for the counter.
            $table->string('payment_mode', 16)->default('full')->after('delivery_fee');
            $table->unsignedTinyInteger('partial_percent')->default(50)->after('payment_mode');
            // On by default: until now every order was cash at the counter, so a
            // shop that never opens these settings keeps working exactly as before.
            $table->boolean('cod_enabled')->default(true)->after('partial_percent');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // What the customer picked at checkout. Null on every order placed
            // before this existed, which were all cash.
            $table->string('payment_choice', 16)->nullable()->after('payment_method');
            // The part of `total` taken online. The rest is due at the counter.
            $table->decimal('online_amount', 10, 2)->default(0)->after('payment_choice');
            // Charged on top of the online portion, never part of `total` — the
            // shop is owed its price, the fee covers the gateway.
            $table->decimal('convenience_fee', 10, 2)->default(0)->after('online_amount');
            $table->timestamp('online_paid_at')->nullable()->after('convenience_fee');
        });

        Schema::table('payments', function (Blueprint $table): void {
            // Set for an order's payment, null for a plan's. The webhook routes on
            // it, so a captured order payment can never be mistaken for a plan
            // purchase and try to activate a subscription.
            $table->foreignId('order_id')->nullable()->after('subscription_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_choice', 'online_amount', 'convenience_fee', 'online_paid_at']);
        });

        Schema::table('business_service_settings', function (Blueprint $table): void {
            $table->dropColumn(['payment_mode', 'partial_percent', 'cod_enabled']);
        });
    }
};
