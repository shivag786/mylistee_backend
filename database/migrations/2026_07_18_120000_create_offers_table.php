<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business offers — the rewards a customer can win on the spinner
 * (document/phase/10 §offers, phase/07 §Offer Management). Free-plan limits
 * (max 3 active, 3-day validity) are enforced in the service layer, not here,
 * so they stay configurable (phase/02 §AI Development Rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32);
            $table->string('reward_value')->nullable(); // human label, e.g. "20%", "1 Free Coffee"
            $table->string('image_path')->nullable();

            $table->date('starts_at');
            $table->date('ends_at');

            $table->unsignedInteger('total_quantity')->nullable();   // null = unlimited
            $table->unsignedInteger('remaining_quantity')->nullable();
            $table->unsignedInteger('weight')->default(1);           // spinner probability weight
            $table->unsignedInteger('priority')->default(0);         // display order

            $table->string('status', 32)->default('active')->index();
            $table->boolean('premium_only')->default(false);
            $table->string('visibility', 32)->default('public');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
