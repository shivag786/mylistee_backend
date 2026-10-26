<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan access, as data the admin edits (owner request):
 *  - modules: which modules a plan includes, each optionally for N days
 *    ({"ordering": {"on": true, "days": 15}, …}). Null = the old behaviour
 *    (everything on), so existing plans change nothing until edited.
 *  - duration_days: how long one purchase lasts (overrides the interval); on
 *    the free plan, how long the free period is. days_from is when the free
 *    days started counting, so a limit set today never expires older shops at
 *    once.
 *  - max_products: menu size cap.
 *  - intro offer: a first-time price for a first period, valid until a date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('features');
            $table->unsignedInteger('duration_days')->nullable()->after('interval');
            $table->timestamp('days_from')->nullable()->after('duration_days');
            $table->unsignedInteger('max_products')->nullable()->after('max_gallery_images');
            $table->decimal('intro_price', 10, 2)->nullable()->after('price');
            $table->unsignedInteger('intro_days')->nullable()->after('intro_price');
            $table->timestamp('intro_ends_at')->nullable()->after('intro_days');
            $table->string('intro_label', 60)->nullable()->after('intro_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'modules', 'duration_days', 'days_from', 'max_products',
                'intro_price', 'intro_days', 'intro_ends_at', 'intro_label',
            ]);
        });
    }
};
