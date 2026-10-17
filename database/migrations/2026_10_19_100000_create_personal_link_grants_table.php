<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every personal-link package a shop ever got — the ledger behind
 * businesses.personal_link_expires_at. One row per trial, online purchase,
 * admin free grant or admin payment link:
 *
 *  - source:  trial | online | admin_free | admin_link
 *  - status:  pending (a payment link not paid yet) | active (days added)
 *             | cancelled (link withdrawn / expired unpaid)
 *  - expires_before / expires_after: the shop's expiry on either side of this
 *    package, so "bought with 10 days left" is visible and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_link_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('status', 16)->default('active');
            $table->string('pack', 16)->nullable();          // month | quarter | year | null = custom
            $table->unsignedSmallInteger('days');
            $table->decimal('amount', 10, 2)->default(0);    // ₹ paid / to be paid (0 = free)
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('link_id')->nullable()->unique(); // Razorpay payment link id (plink_…)
            $table->string('link_url')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_before')->nullable();
            $table->timestamp('expires_after')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        // Backfill what already happened, so history starts complete.
        $now = now();
        foreach (DB::table('businesses')->whereNotNull('personal_link_trial_used_at')->get(['id', 'personal_link_trial_used_at']) as $b) {
            $paid = DB::table('payments')->where('business_id', $b->id)
                ->where('purpose', 'personal_link')->where('status', 'captured')->get();

            if ($paid->isEmpty()) {
                DB::table('personal_link_grants')->insert([
                    'uuid' => (string) Str::uuid(), 'business_id' => $b->id, 'source' => 'trial', 'status' => 'active',
                    'days' => 7, 'amount' => 0, 'applied_at' => $b->personal_link_trial_used_at,
                    'expires_after' => \Illuminate\Support\Carbon::parse($b->personal_link_trial_used_at)->addDays(7),
                    'created_at' => $b->personal_link_trial_used_at, 'updated_at' => $now,
                ]);
            }
            foreach ($paid as $p) {
                $meta = json_decode((string) $p->meta, true) ?: [];
                DB::table('personal_link_grants')->insert([
                    'uuid' => (string) Str::uuid(), 'business_id' => $b->id, 'source' => 'online', 'status' => 'active',
                    'pack' => $meta['pack'] ?? null, 'days' => (int) ($meta['days'] ?? 0), 'amount' => $p->amount,
                    'payment_id' => $p->id, 'applied_at' => $p->paid_at, 'created_at' => $p->created_at, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_link_grants');
    }
};
