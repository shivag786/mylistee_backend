<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dining tables for a business (restaurants/hotels). Optional: a business with no
 * tables simply takes dine-in "to the waiter" or uses pickup. Each table's QR
 * encodes the public profile URL + ?table={uuid} (rendered client-side, like the
 * primary QR — no image is stored here). document/phase/10 §QR TABLES foresaw this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('label', 40);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('scan_count')->default(0);
            $table->string('status', 16)->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_tables');
    }
};
