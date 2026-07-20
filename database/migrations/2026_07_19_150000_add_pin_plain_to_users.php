<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plaintext copy of the PIN so the admin panel can display each business owner's
 * login credentials.
 *
 * ⚠️ SECURITY: storing a credential in plaintext is intentionally insecure and
 * is a DEMO convenience only. Drop this column (and the admin display) before a
 * real production launch — see SECURITY_AUDIT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pin_plain')->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('pin_plain');
        });
    }
};
