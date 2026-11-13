<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant POS layer (owner request, Nov 2026):
 *
 *  - GST billing: a shop's tax settings, a GST rate per product/combo, the tax
 *    worked out on each order and a financial-year invoice number once paid.
 *  - Staff & roles: people who sign in to the vendor app with the shop code,
 *    their mobile and a PIN, limited to what the owner ticks.
 *  - Day close: cash in/out of the drawer and the end-of-day count.
 *  - Attendance: check in/out with a selfie (own phone with GPS, or the shop's
 *    tablet with the staff PIN).
 *  - Owner alerts: what staff did that the owner should know about.
 *  - Module usage: billable units for modules a plan charges per use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_pos_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            // GST
            $table->boolean('gst_enabled')->default(false);
            $table->string('legal_name', 160)->nullable();
            $table->string('fssai', 32)->nullable();
            $table->boolean('prices_include_tax')->default(false);
            $table->decimal('default_tax_rate', 5, 2)->default(5);
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            $table->boolean('round_off')->default(true);
            $table->string('invoice_prefix', 6)->nullable();
            $table->string('bill_footer', 200)->nullable();
            // Business day: a late-night shop's day runs past midnight to this hour.
            $table->unsignedTinyInteger('day_start_hour')->default(4);
            $table->json('alert_settings')->nullable();
            $table->json('attendance_settings')->nullable();
            $table->timestamps();
        });

        // Short code staff type to sign in to this shop.
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('staff_code', 8)->nullable()->unique()->after('slug');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('selling_price');
        });
        Schema::table('combos', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('combo_price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('unit_price');
            $table->decimal('taxable_value', 10, 2)->nullable()->after('tax_rate');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('taxable_value');
        });

        Schema::table('orders', function (Blueprint $table) {
            // null = no GST on this order; 'exclusive' / 'inclusive' as priced.
            $table->string('tax_mode', 12)->nullable()->after('total');
            $table->decimal('taxable_amount', 10, 2)->default(0)->after('tax_mode');
            $table->decimal('cgst', 10, 2)->default(0)->after('taxable_amount');
            $table->decimal('sgst', 10, 2)->default(0)->after('cgst');
            $table->decimal('service_charge', 10, 2)->default(0)->after('sgst');
            $table->decimal('service_charge_percent', 5, 2)->default(0)->after('service_charge');
            $table->decimal('round_off', 6, 2)->default(0)->after('service_charge_percent');
            $table->decimal('manual_discount', 10, 2)->default(0)->after('coupon_discount');
            $table->decimal('manual_discount_percent', 5, 2)->nullable()->after('manual_discount');
            $table->string('discount_reason', 120)->nullable()->after('manual_discount_percent');
            $table->string('invoice_no', 20)->nullable()->after('serial');
            $table->foreignId('created_by')->nullable()->after('paid_by')->constrained('users')->nullOnDelete();
            $table->foreignId('discount_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('discount_by')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 160)->nullable()->after('cancelled_by');
            $table->index(['business_id', 'paid_at']);
            $table->index(['business_id', 'invoice_no']);
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('fy', 8); // "2627" for FY 2026-27
            $table->unsignedInteger('last')->default(0);
            $table->unique(['business_id', 'fy']);
        });

        Schema::create('business_staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('phone', 16);
            $table->string('role', 16)->default('waiter');
            $table->json('permissions')->nullable();
            $table->decimal('max_discount_percent', 5, 2)->nullable();
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->json('week_off')->nullable(); // ISO weekdays, 1 = Monday
            $table->decimal('monthly_salary', 10, 2)->nullable();
            $table->date('joined_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'phone']);
        });

        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('business_staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12)->default('present'); // present, late, half_day, absent, leave, week_off
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('check_in_method', 10)->nullable(); // self, kiosk, manual
            $table->string('check_out_method', 10)->nullable();
            $table->string('check_in_photo')->nullable();
            $table->string('check_out_photo')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->unsignedInteger('check_in_distance_m')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'date']);
            $table->index(['business_id', 'date']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('type', 4); // in, out
            $table->decimal('amount', 10, 2);
            $table->string('reason', 120);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'business_date']);
        });

        Schema::create('day_closings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('opening_cash', 10, 2)->default(0);
            $table->decimal('expected_cash', 10, 2)->default(0);
            $table->decimal('counted_cash', 10, 2)->default(0);
            $table->decimal('difference', 10, 2)->default(0);
            // What stays in the drawer for tomorrow (tomorrow's opening cash).
            $table->decimal('cash_left', 10, 2)->default(0);
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->json('summary')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'business_date']);
        });

        Schema::create('owner_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('severity', 8)->default('info'); // info, warning, danger
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // One alert per key per shop (e.g. "late:2026-11-12:15").
            $table->string('dedupe_key', 80)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'dedupe_key']);
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('module_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('module', 32);
            $table->string('unit', 16); // order, staff_month
            $table->string('period', 7); // 2026-11
            $table->string('reference', 64); // what was counted, unique per module+period
            $table->decimal('rate', 10, 2);
            $table->timestamps();
            $table->unique(['business_id', 'module', 'period', 'reference'], 'module_usage_unique');
            $table->index(['period', 'module']);
        });

        // The admin's global switches for the new owner modules start on.
        $now = now();
        foreach ([
            'billing' => ['Owner menu: GST billing & day close', 'GST bills, invoice numbers, day-end report and cash count'],
            'staff' => ['Owner menu: Staff & roles', 'Staff logins with permissions, and alerts on what they do'],
            'attendance' => ['Owner menu: Staff attendance', 'Selfie + GPS or shop-tablet check in/out, late alerts, monthly summary'],
        ] as $key => [$name, $description]) {
            if (! DB::table('feature_flags')->where('key', "owner_{$key}")->exists()) {
                DB::table('feature_flags')->insert([
                    'key' => "owner_{$key}",
                    'name' => $name,
                    'description' => $description,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', ['owner_billing', 'owner_staff', 'owner_attendance'])->delete();
        Schema::dropIfExists('module_usages');
        Schema::dropIfExists('owner_alerts');
        Schema::dropIfExists('day_closings');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('staff_attendances');
        Schema::dropIfExists('business_staff');
        Schema::dropIfExists('invoice_counters');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'paid_at']);
            $table->dropIndex(['business_id', 'invoice_no']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('discount_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'tax_mode', 'taxable_amount', 'cgst', 'sgst', 'service_charge', 'service_charge_percent',
                'round_off', 'manual_discount', 'manual_discount_percent', 'discount_reason', 'invoice_no', 'cancel_reason',
            ]);
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['tax_rate', 'taxable_value', 'tax_amount']));
        Schema::table('combos', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['staff_code']);
            $table->dropColumn('staff_code');
        });
        Schema::dropIfExists('business_pos_settings');
    }
};
