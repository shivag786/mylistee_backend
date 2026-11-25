<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI credits (2026-11-20):
 *  - plans.ai_answers_per_month: AI answers a shop on the plan gets each month
 *    (null = unlimited, 0 = none). Backfilled from the old single admin limit.
 *  - businesses.ai_credits: add-on answers bought with Razorpay (or given by
 *    the admin). Used only after the month's plan answers; they don't expire.
 *  - ai_credit_packs: the add-on packs the admin sells.
 *  - ai_usage: one row per AI call, so the admin sees who uses how much and
 *    how close the shop is to the provider's daily free quota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('ai_answers_per_month')->nullable()->after('whatsapp_per_month');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedInteger('ai_credits')->default(0);
        });

        Schema::create('ai_credit_packs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80);
            $table->unsignedInteger('answers');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('kind', 40);
            // plan | addon (an owner's AI answer), scan (menu scan), system (nightly ideas), refresh (owner asked for new ideas)
            $table->string('source', 16);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index('created_at');
        });

        // Keep today's behaviour: every plan gets the old single limit.
        $old = DB::table('settings')->where('key', 'aiMonthlyLimit')->value('value');
        $decoded = is_string($old) ? json_decode($old, true) : null;
        $limit = is_array($decoded) ? (int) ($decoded['value'] ?? 100) : (is_numeric($decoded) ? (int) $decoded : 100);
        Plan::query()->update(['ai_answers_per_month' => max(0, $limit)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_credit_packs');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('ai_credits'));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('ai_answers_per_month'));
    }
};
