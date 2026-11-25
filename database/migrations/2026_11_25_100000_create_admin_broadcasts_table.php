<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin push broadcasts: who it went to, how many could get it on a phone and
 * how many pushes Firebase accepted — so the admin sees each send's result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            // all | customers | owners | shop_customers | test
            $table->string('target', 32);
            // shop_customers: the shop and which of its customers.
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('audience', 32)->nullable();
            // owners: only shops in this category.
            $table->foreignId('category_id')->nullable()->constrained('business_categories')->nullOnDelete();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('reachable')->default(0);
            $table->unsignedInteger('pushed')->nullable();
            $table->unsignedInteger('failed')->nullable();
            // queued | sending | sent | failed
            $table->string('status', 16)->default('queued');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_broadcasts');
    }
};
