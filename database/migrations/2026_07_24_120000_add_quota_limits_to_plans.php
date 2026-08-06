<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan quota limits for combos, promotions and monthly customer push
 * notifications (owner request). Additive & nullable (null = unlimited), mirroring
 * the existing max_active_offers pattern so the Super Admin tunes them without a
 * deploy. Existing plan rows are backfilled by key so live DBs get sane values.
 */
return new class extends Migration
{
    /** @var array<string, array{combos: ?int, promotions: ?int, push: ?int}> */
    private const DEFAULTS = [
        'free' => ['combos' => 2, 'promotions' => 2, 'push' => 4],
        'starter' => ['combos' => 10, 'promotions' => 10, 'push' => 30],
        'pro' => ['combos' => null, 'promotions' => null, 'push' => null],
        'enterprise' => ['combos' => null, 'promotions' => null, 'push' => null],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_active_combos')->nullable()->after('max_active_offers');
            $table->unsignedInteger('max_active_promotions')->nullable()->after('max_active_combos');
            $table->unsignedInteger('max_push_per_month')->nullable()->after('max_active_promotions');
        });

        // Backfill existing plans so current subscribers get quotas immediately.
        foreach (self::DEFAULTS as $key => $limits) {
            Plan::where('key', $key)->update([
                'max_active_combos' => $limits['combos'],
                'max_active_promotions' => $limits['promotions'],
                'max_push_per_month' => $limits['push'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['max_active_combos', 'max_active_promotions', 'max_push_per_month']);
        });
    }
};
