<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business managed by an owner (document/phase/10 §businesses, phase/07).
 * Belongs to a `users` row with role=business_owner — the single-users-table
 * decision from Milestone 3 supersedes phase/10's separate business_owners table.
 * Denormalized counters (rating/visits/spins/rewards) are maintained by later
 * milestones; they default to 0 here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('business_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_name')->nullable();
            $table->text('description')->nullable();

            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();

            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('gst', 32)->nullable();

            $table->string('status', 32)->default('active')->index();
            $table->boolean('verified')->default(false);
            $table->boolean('featured')->default(false)->index();

            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('total_visits')->default(0);
            $table->unsignedInteger('total_spins')->default(0);
            $table->unsignedInteger('total_rewards')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
