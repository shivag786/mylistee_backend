<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer profile: date of birth, gender and anniversary on the account, and
 * the people a customer celebrates (family, friends) with their own dates —
 * so favourite shops can send birthday and anniversary offers on time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('dob')->nullable()->after('phone');
            $table->string('gender', 12)->nullable()->after('dob');
            $table->date('anniversary')->nullable()->after('gender');
        });

        Schema::create('customer_people', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('relation', 30)->nullable();
            $table->string('gender', 12)->nullable();
            $table->date('dob')->nullable();
            $table->date('anniversary')->nullable();
            // The year a reminder last went out for each date, so it goes once a year.
            $table->unsignedSmallInteger('dob_reminded_year')->nullable();
            $table->unsignedSmallInteger('anniversary_reminded_year')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dob_reminded_year')->nullable()->after('anniversary');
            $table->unsignedSmallInteger('anniversary_reminded_year')->nullable()->after('dob_reminded_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_people');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['dob', 'gender', 'anniversary', 'dob_reminded_year', 'anniversary_reminded_year']);
        });
    }
};
