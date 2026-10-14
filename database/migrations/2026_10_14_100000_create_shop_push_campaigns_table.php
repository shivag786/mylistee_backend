<?php

use App\Models\FeatureFlag;
use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop → customer push notifications. Each send is a campaign row; the plan's
 * `push_reach_per_month` caps how many customers a shop can reach in a month
 * (null = unlimited), alongside the existing `max_push_per_month` send count.
 * The owner menu module starts OFF — an admin switches it on.
 */
return new class extends Migration
{
    /** Starting reach per plan key; the admin edits these on the Plans page. */
    private const REACH = ['free' => 50, 'starter' => 500, 'pro' => 2000];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('push_reach_per_month')->nullable()->after('max_push_per_month');
        });

        foreach (self::REACH as $key => $reach) {
            Plan::where('key', $key)->update(['push_reach_per_month' => $reach]);
        }

        Schema::create('shop_push_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('audience', 24);
            $table->string('title', 80);
            $table->string('body', 200)->nullable();
            $table->string('link')->nullable();
            // Customers messaged (counted against the monthly reach) and, of
            // those, how many had a device that can show a push.
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('reachable')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        FeatureFlag::firstOrCreate(
            ['key' => 'owner_notifications'],
            [
                'name' => 'Owner menu: Notify customers',
                'description' => 'Shops send push notifications to their followers and customers',
                'enabled' => false,
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_push_campaigns');
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('push_reach_per_month');
        });
        FeatureFlag::where('key', 'owner_notifications')->delete();
    }
};
