<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the default users table for the platform's auth model
 * (document/phase/12 §Firebase Login Flow, phase/02 §Roles).
 *
 * Single users table with a `role` column (Customer / Business Owner / Admin)
 * rather than per-role tables. Firebase is the identity provider; `firebase_uid`
 * links a local user to their Firebase account. `password` becomes nullable
 * because social-auth users never set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->after('id')->nullable()->unique();
            $table->string('firebase_uid')->after('uuid')->nullable()->unique();
            $table->string('avatar_url')->after('email_verified_at')->nullable();
            $table->string('phone', 32)->after('avatar_url')->nullable();
            $table->string('role', 32)->after('phone')->default('customer')->index();
            $table->string('status', 32)->after('role')->default('active')->index();
            $table->string('provider', 32)->after('status')->default('google');
            $table->timestamp('last_login_at')->after('provider')->nullable();
            $table->softDeletes();

            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['firebase_uid']);
            $table->dropColumn([
                'uuid',
                'firebase_uid',
                'avatar_url',
                'phone',
                'role',
                'status',
                'provider',
                'last_login_at',
                'deleted_at',
            ]);

            $table->string('password')->nullable(false)->change();
        });
    }
};
