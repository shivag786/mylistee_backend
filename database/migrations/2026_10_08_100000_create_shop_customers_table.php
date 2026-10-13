<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's own customer book. Separate from `users`: a walk-in customer is
 * known to the shop by mobile number alone and never has to sign up, and the
 * owner can correct any detail without touching a platform account. One row
 * per mobile per shop; the number is stored as its 10 digits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('phone', 10);
            $table->string('name', 80)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('city', 80)->nullable();
            // How the row first appeared: added by the owner, or by a review.
            $table->string('source', 16)->default('manual');
            $table->timestamps();

            $table->unique(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_customers');
    }
};
