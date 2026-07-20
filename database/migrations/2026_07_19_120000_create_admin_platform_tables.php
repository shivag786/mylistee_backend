<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin platform tables (document/phase/14): key-value platform settings,
 * feature flags, CMS pages and an immutable audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform settings (brand, currency, timezone, maintenance mode, …)
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 64)->default('general')->index();
            $table->timestamps();
        });

        // Feature flags — toggle features without a deploy (phase/14 §Feature Flags)
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // CMS pages — About / Privacy / Terms / FAQ / … (phase/14 §CMS Management)
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('status', 16)->default('published'); // draft / published
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Audit log — every important admin action (phase/14 §Audit Logs). Immutable:
        // only created_at, never updated or deleted from the app.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();      // business.suspend, plan.update, …
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('settings');
    }
};
