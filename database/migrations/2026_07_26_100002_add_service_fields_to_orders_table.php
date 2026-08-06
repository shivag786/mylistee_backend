<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach a service context to each order without touching the existing flow.
 * `service_type` defaults to 'pickup' so every prior order (and every bakery)
 * stays valid. `table_id` binds a dine-in order to a table; `service_address`
 * holds a delivery address; `delivery_fee` is snapshotted into the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('service_type', 16)->default('pickup')->after('status')->index();
            $table->foreignId('table_id')->nullable()->after('customer_id')
                ->constrained('business_tables')->nullOnDelete();
            $table->text('service_address')->nullable()->after('note');
            $table->decimal('delivery_fee', 10, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('table_id');
            $table->dropColumn(['service_type', 'service_address', 'delivery_fee']);
        });
    }
};
