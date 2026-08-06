<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.5 follow-ups:
 *  - orders.payment_method — how the counter was paid (cod/online), set when the
 *    owner marks an order paid. Nullable: unpaid/cancelled orders have none.
 *  - reviews.order_id — the completed order that verified this review, so reviews
 *    are tied to a real purchase. Nullable to preserve pre-existing reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_method', 16)->nullable()->after('total');
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('customer_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};
