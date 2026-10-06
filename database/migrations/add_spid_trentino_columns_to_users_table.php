<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('fiscal_code')->nullable()->unique();
            $table->string('surname')->nullable();
            $table->string('preferred_username')->nullable();
            $table->string('locale')->nullable();
            $table->string('zoneinfo')->nullable();
            $table->json('spid_profile')->nullable();

            // SPID users have no local password and may have no email.
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_code']);
            $table->dropColumn(['fiscal_code', 'surname', 'preferred_username', 'locale', 'zoneinfo', 'spid_profile']);
        });
    }
};
