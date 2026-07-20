<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit to a business profile — recorded when the public profile is opened
 * from a QR scan or discovery (document/phase/02 §Customer Visit). Powers the
 * analytics dashboard (Milestone 12). Visits are counted for logged-out
 * visitors too, so customer_id is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_visits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('device')->nullable();
            $table->string('referrer')->nullable();
            $table->string('source', 32)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_visits');
    }
};
