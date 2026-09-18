<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The city a business is in, kept apart from the free-text address.
 *
 * `address` is one line the owner types however they like, so it cannot be
 * grouped or filtered on. The owner fills this from their own location and we
 * read the city off Google's answer, which gives every business the same
 * spelling of the same place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            // Indexed: this is meant to be filtered and grouped by, which is
            // the whole reason it is not just part of `address`.
            $table->string('city')->nullable()->after('address')->index();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['city']);
            $table->dropColumn('city');
        });
    }
};
