<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Rewards & coupons" gets its own admin switch (`owner_rewards`), apart from
 * "Loyalty coins" (`owner_loyalty`). The new switch starts on; the coins
 * switch keeps whatever the admin set and is only renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (! DB::table('feature_flags')->where('key', 'owner_rewards')->exists()) {
            DB::table('feature_flags')->insert([
                'key' => 'owner_rewards',
                'name' => 'Owner menu: Rewards & coupons',
                'description' => 'Bill discount, come-back and loyalty-step coupons, coupon codes — for shops and customers',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('feature_flags')->where('key', 'owner_loyalty')->update([
            'name' => 'Owner menu: Loyalty coins',
            'description' => 'Listee coins customers earn and spend — for shops and customers',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'owner_rewards')->delete();
        DB::table('feature_flags')->where('key', 'owner_loyalty')->update([
            'name' => 'Owner menu: Loyalty / Coins',
            'description' => 'Loyalty program and coin rewards',
        ]);
    }
};
