<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new SpidTransactionLog)->getTable(), function (Blueprint $table): void {
            $table->id();

            $table->string('transaction_id', 64)->index();
            $table->string('event_type', 50)->index();

            // Search fields suggested by the SPID/CIE OIDC log management rules.
            $table->string('authorization_code', 512)->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('jti')->nullable()->index();
            $table->string('iss', 512)->nullable()->index();
            $table->string('sub')->nullable()->index();
            $table->timestamp('iat')->nullable()->index();
            $table->timestamp('exp')->nullable()->index();

            // Encrypted JSON of the message and HMAC-SHA256 of the plaintext.
            $table->text('payload');
            $table->string('payload_hmac', 64);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();
            $table->index('created_at');
            $table->index(['sub', 'created_at']);
            $table->index(['transaction_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new SpidTransactionLog)->getTable());
    }
};
