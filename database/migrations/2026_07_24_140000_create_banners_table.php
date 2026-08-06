<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage advertisement banners (admin-managed). Each banner has a placement
 * slot, an optional link, a schedule window (start/end datetime) and an active
 * toggle. Multiple banners in a slot rotate on the homepage. Positions order
 * them within a slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('title');                 // admin reference / alt text
            $table->string('image_path');
            $table->string('link_url', 512)->nullable();
            $table->string('placement', 40)->index(); // home_top | home_after_combos
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['placement', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
