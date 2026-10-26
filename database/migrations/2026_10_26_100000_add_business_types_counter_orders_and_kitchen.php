<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor app — kitchen and counter:
 *  - business_categories.business_type: food / bakery / retail / service. It
 *    decides which tools a shop sees (the kitchen screen only for food and
 *    bakery). Existing categories are sorted by name; anything unknown stays
 *    food, and the admin can change it.
 *  - businesses.kitchen_enabled: the owner's own switch; null follows the type.
 *  - orders: walk-in orders taken at the counter have no customer account, so
 *    customer_id may be null and the name/phone said at the counter is kept in
 *    customer_label. kitchen_status tracks the order through the kitchen
 *    (queued → preparing → ready) without touching the order status itself.
 */
return new class extends Migration
{
    private const TYPES = [
        'bakery' => ['bakery', 'sweet', 'cake', 'mithai', 'confection'],
        'retail' => ['grocery', 'kirana', 'medical', 'pharma', 'chemist', 'stationery', 'clothing', 'garment',
            'electronic', 'mobile', 'paan', 'hardware', 'general store', 'supermarket', 'book', 'gift', 'jewel', 'footwear'],
        'service' => ['salon', 'spa', 'gym', 'fitness', 'coaching', 'institute', 'tuition', 'service center',
            'repair', 'parlour', 'parlor', 'clinic', 'laundry', 'studio'],
    ];

    public function up(): void
    {
        Schema::table('business_categories', function (Blueprint $table): void {
            $table->string('business_type', 16)->default('food');
        });

        foreach (DB::table('business_categories')->get(['id', 'name', 'slug']) as $category) {
            $haystack = strtolower($category->name.' '.$category->slug);
            foreach (self::TYPES as $type => $words) {
                foreach ($words as $word) {
                    if (str_contains($haystack, $word)) {
                        DB::table('business_categories')->where('id', $category->id)->update(['business_type' => $type]);
                        continue 3;
                    }
                }
            }
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->boolean('kitchen_enabled')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('source', 16)->default('app');
            $table->string('customer_label', 80)->nullable();
            $table->string('kitchen_status', 16)->nullable()->index();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['kitchen_status']);
            $table->dropColumn(['source', 'customer_label', 'kitchen_status', 'kitchen_started_at', 'ready_at']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('kitchen_enabled');
        });

        Schema::table('business_categories', function (Blueprint $table): void {
            $table->dropColumn('business_type');
        });
    }
};
