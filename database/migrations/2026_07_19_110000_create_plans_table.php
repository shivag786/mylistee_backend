<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans (document/phase/02 §Subscriptions, phase/14 §Subscription
 * Management). Limits are stored as data, NEVER hardcoded (phase/02 §AI Rules:
 * "Never assume package limitations") so the Super Admin can tune them without a
 * deploy. A null numeric limit means "unlimited". Capabilities live in `features`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key')->unique(); // free / starter / pro / enterprise / …
            $table->string('name');
            $table->string('description')->nullable();

            // Pricing
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('interval', 16)->default('month'); // month / year / lifetime

            // Configurable limits (null = unlimited)
            $table->unsignedInteger('max_active_offers')->nullable();
            $table->unsignedInteger('max_offer_days')->nullable();
            $table->unsignedInteger('max_qr_codes')->nullable()->default(1);
            $table->unsignedInteger('max_gallery_images')->nullable();

            // Capability keys (analytics, push_notifications, scheduled_campaigns, …)
            $table->json('features')->nullable();

            $table->string('badge')->nullable(); // e.g. "Popular"
            $table->boolean('is_public')->default(true);   // shown on pricing
            $table->boolean('is_default')->default(false);  // the free fallback
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
