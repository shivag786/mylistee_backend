<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "Rewards & coupons" setup per shop, the coupons customers apply at
 * checkout, and on each order: the discounts given and Listee's fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The shop's three automatic rewards, each a small JSON rule (null = off):
        //  bill_discount  {min_bill, type, value, max_off}         — at checkout
        //  comeback       {type, value, max_off, min_bill, days}   — coupon after an order
        //  loyalty        {every, type, value, max_off}            — coupon every Nth order
        Schema::create('shop_reward_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            $table->json('bill_discount')->nullable();
            $table->json('comeback')->nullable();
            $table->json('loyalty')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // Set for a coupon earned by one customer (comeback / loyalty).
            $table->foreignId('customer_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('title', 80);
            $table->string('kind', 16)->default('code'); // code | comeback | loyalty
            $table->string('discount_type', 8); // percent | flat
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_bill', 10, 2)->nullable();
            $table->unsignedInteger('per_customer_limit')->default(1);
            $table->unsignedInteger('usage_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'customer_id', 'active']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['coupon_id', 'customer_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('bill_discount', 10, 2)->default(0)->after('coin_discount');
            $table->foreignId('coupon_id')->nullable()->after('bill_discount')->constrained('coupons')->nullOnDelete();
            $table->decimal('coupon_discount', 10, 2)->default(0)->after('coupon_id');
            // Listee's cut, borne by the shop: platform share on every order, the
            // payment gateway's share on the online part. The shop keeps the rest.
            $table->decimal('platform_fee', 10, 2)->default(0)->after('convenience_fee');
            $table->decimal('gateway_fee', 10, 2)->default(0)->after('platform_fee');
            $table->decimal('vendor_earning', 10, 2)->nullable()->after('gateway_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['bill_discount', 'coupon_discount', 'platform_fee', 'gateway_fee', 'vendor_earning']);
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('shop_reward_settings');
    }
};
