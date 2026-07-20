<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent QR code per business (document/phase/10 §qr_codes, phase/02 §QR Code
 * Rules — one permanent code that always opens the business profile and never
 * changes). The image is rendered client-side from `url`; counters track usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('type', 32)->default('primary');
            $table->string('url');
            $table->string('image_path')->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
