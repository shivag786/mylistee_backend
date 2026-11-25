<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public website (contact form, city and guide pages):
 * shop owners asking about Listee, partners, anyone with a question. The
 * Listee team follows each one up from Admin → Enquiries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('phone', 20);
            $table->string('email', 120)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('business_name', 120)->nullable();
            $table->string('business_type', 60)->nullable();
            // shop | partner | support | other
            $table->string('topic', 20)->default('shop');
            $table->text('message')->nullable();
            // The page it was sent from, e.g. /pos-software/jhansi.
            $table->string('source', 255)->nullable();
            // new | contacted | converted | closed
            $table->string('status', 16)->default('new')->index();
            $table->text('notes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
