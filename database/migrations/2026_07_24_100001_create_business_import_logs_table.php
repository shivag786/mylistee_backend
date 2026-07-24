<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-011 §ADMIN LOG — one immutable record per import attempt. Captures who
 * imported, when, which business, the outcome and the exact fields written, so
 * every admin import is auditable independently of the general audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_import_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('business_id')->nullable()
                ->constrained('businesses')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('source', 32)->default('google');
            $table->string('source_url', 512)->nullable();
            $table->string('place_id')->nullable();

            // preview | created | updated | ignored | failed
            $table->string('status', 32)->index();
            $table->json('updated_fields')->nullable();
            $table->string('message', 512)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_logs');
    }
};
