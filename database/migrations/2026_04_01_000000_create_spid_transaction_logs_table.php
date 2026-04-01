<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('spid-laravel-trentino.transaction_log.table', 'spid_transaction_logs');

        Schema::create($table, function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('transaction_id', 64)->index();
            $table->string('event_type', 50)->index();

            $table->string('authorization_code', 512)->nullable()->index();
            $table->string('client_id', 255)->nullable()->index();
            $table->string('jti', 255)->nullable()->index();
            $table->string('iss', 512)->nullable()->index();
            $table->string('sub', 255)->nullable()->index();
            $table->timestamp('iat')->nullable()->index();
            $table->timestamp('exp')->nullable()->index();

            $table->text('payload');
            $table->string('payload_hmac', 128);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['sub', 'created_at'], 'spid_logs_sub_created_at_index');
            $table->index(['transaction_id', 'event_type'], 'spid_logs_txid_event_type_index');
        });
    }

    public function down(): void
    {
        $table = config('spid-laravel-trentino.transaction_log.table', 'spid_transaction_logs');
        Schema::dropIfExists($table);
    }
};
