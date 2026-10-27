<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan settings the admin edits (owner request):
 *  - duration_months: a plan can last N months instead of N days (only one of
 *    duration_days / duration_months is set).
 *  - is_active: an inactive plan is hidden and can't be bought; shops already
 *    on it keep it until their period ends.
 *  - push_per_customer_per_month: how many notifications one customer can get
 *    from a shop in a month (null = no cap).
 *  - customer_details: whether the shop sees customers' email / phone in full
 *    or masked on the notify screen.
 *
 * shop_push_recipients records who each campaign went to, so the per-customer
 * cap can be counted. All additive; existing plans keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('duration_months')->nullable()->after('duration_days');
            $table->boolean('is_active')->default(true)->after('is_public');
            $table->unsignedInteger('push_per_customer_per_month')->nullable()->after('push_reach_per_month');
            $table->string('customer_details', 10)->default('masked')->after('push_per_customer_per_month');
        });

        Schema::create('shop_push_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('shop_push_campaigns')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_push_recipients');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['duration_months', 'is_active', 'push_per_customer_per_month', 'customer_details']);
        });
    }
};
