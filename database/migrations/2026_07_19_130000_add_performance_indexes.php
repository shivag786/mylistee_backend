<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes (Milestone 15 — optimization) for the analytics (M12) and
 * admin (M14) hot paths. Complements the single-column indexes already on these
 * tables; none of these duplicate an existing index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Admin customer list: WHERE role = customer AND status = ?
            $table->index(['role', 'status'], 'users_role_status_index');
        });

        Schema::table('spins', function (Blueprint $table): void {
            // Analytics: spins for a business over a date range.
            $table->index(['business_id', 'created_at'], 'spins_business_created_index');
        });

        Schema::table('rewards', function (Blueprint $table): void {
            // Analytics: rewards won / redeemed for a business over a range.
            $table->index(['business_id', 'won_at'], 'rewards_business_won_index');
            $table->index(['business_id', 'status'], 'rewards_business_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_role_status_index');
        });
        Schema::table('spins', function (Blueprint $table): void {
            $table->dropIndex('spins_business_created_index');
        });
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropIndex('rewards_business_won_index');
            $table->dropIndex('rewards_business_status_index');
        });
    }
};
