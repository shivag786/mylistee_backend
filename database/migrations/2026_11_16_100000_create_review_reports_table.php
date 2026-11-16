<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * review_reports: a signed-in customer flags someone else's review (spam,
 * offensive, fake…). One report per customer per review; the admin sees the
 * count on the Reviews page and hides the review if it breaks the rules.
 * Google Play expects apps with user reviews to let users report them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('review_reports')) {
            return;
        }

        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};
