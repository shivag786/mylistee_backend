<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal link — store mode sold on its own, by the day. A shop switches it on
 * (7-day free trial, once), then buys 30 / 90 / 365-day packs.
 *
 *  - personal_link_enabled:        the owner's switch;
 *  - personal_link_expires_at:     when the paid/trial days run out;
 *  - personal_link_trial_used_at:  set once, so the trial is never given twice;
 *  - personal_link_reminded_on:    the last day an expiry reminder went out,
 *                                  so the daily job sends at most one a day.
 *
 * Payments get a `purpose` so a personal-link payment never reaches the plan
 * activation path (and vice versa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->boolean('personal_link_enabled')->default(false);
            $table->timestamp('personal_link_expires_at')->nullable()->index();
            $table->timestamp('personal_link_trial_used_at')->nullable();
            $table->date('personal_link_reminded_on')->nullable();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('purpose', 24)->default('plan')->index();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['personal_link_expires_at']);
            $table->dropColumn([
                'personal_link_enabled',
                'personal_link_expires_at',
                'personal_link_trial_used_at',
                'personal_link_reminded_on',
            ]);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
