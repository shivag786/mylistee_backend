<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp through Interakt (admin → WhatsApp):
 *  - whatsapp_templates: message templates made here (submitted to Interakt
 *    for Meta's approval) or synced from the Interakt account, with how each
 *    {{n}} variable is filled and whether shops may use the template.
 *  - whatsapp_campaigns: one WhatsApp offer a shop sent to its customers.
 *  - whatsapp_messages: every message sent, with its delivery status (kept up
 *    to date by the Interakt webhook). Also what plan quotas are counted from.
 *  - whatsapp_opt_outs: numbers that replied STOP; never sent marketing again.
 *  - plans.whatsapp_per_month: WhatsApp messages a shop may send a month
 *    (null = unlimited, 0 = none).
 *
 * All additive; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Interakt / Meta template name (lowercase, underscores) — what a send names.
            $table->string('name', 120);
            $table->string('display_name', 120)->nullable();
            $table->string('language', 10)->default('en');
            // MARKETING | UTILITY | AUTHENTICATION
            $table->string('category', 20);
            // null | TEXT | IMAGE | VIDEO | DOCUMENT
            $table->string('header_format', 20)->nullable();
            $table->string('header', 120)->nullable();
            $table->string('header_media_url', 2048)->nullable();
            $table->text('body');
            $table->string('footer', 120)->nullable();
            $table->json('buttons')->nullable();
            // Sample values Meta reviews the template with.
            $table->json('samples')->nullable();
            // How each variable is filled: {header: {...}, body: [{source, label}], buttons: {"0": {...}}}
            $table->json('variables')->nullable();
            // Ready-made for shops: owners can send it as a WhatsApp offer.
            $table->boolean('for_shops')->default(false);
            $table->string('status', 20)->default('DRAFT');
            $table->string('rejection_reason', 500)->nullable();
            $table->string('interakt_id', 64)->nullable()->index();
            $table->string('wa_template_id', 64)->nullable()->index();
            $table->string('source', 10)->default('listee'); // listee | interakt (synced)
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->string('audience', 20);
            // What the owner typed into the template's own fields.
            $table->json('values')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('whatsapp_campaigns')->cascadeOnDelete();
            // otp | order_placed_owner | order_accepted | … | campaign | test
            $table->string('purpose', 40);
            $table->string('phone', 20);
            // queued | sent | delivered | read | failed
            $table->string('status', 20)->default('queued');
            $table->string('error', 500)->nullable();
            $table->string('interakt_message_id', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['business_id', 'campaign_id', 'created_at']);
            $table->index(['business_id', 'phone', 'created_at']);
        });

        Schema::create('whatsapp_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('whatsapp_per_month')->nullable()->after('push_per_customer_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('whatsapp_per_month');
        });
        Schema::dropIfExists('whatsapp_opt_outs');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_campaigns');
        Schema::dropIfExists('whatsapp_templates');
    }
};
