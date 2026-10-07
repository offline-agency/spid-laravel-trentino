<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

/*
 * Named without a timestamp so it sorts (and runs) after
 * create_spid_transaction_logs_table. Existing rows are not modified:
 * they keep their APP_KEY HMAC and stay outside the hash chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new SpidTransactionLog)->getTable(), function (Blueprint $table): void {
            $table->string('key_id', 32)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('chain_hash', 64)->nullable()->index();
        });

        // Single row: the head of the hash chain, also the lock taken by every append.
        Schema::create(SpidTransactionLog::headsTable(), function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('head_id')->nullable();
            $table->string('head_hash', 64)->nullable();
            $table->timestamps();
        });

        // Seeded here so concurrent first appends never race to create it.
        $now = CarbonImmutable::now();
        (new SpidTransactionLog)->getConnection()->table(SpidTransactionLog::headsTable())->insert([
            'id' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Anchors written by spid:prune-logs, so verification survives pruning.
        Schema::create(SpidTransactionLog::checkpointsTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('last_id');
            $table->string('last_chain_hash', 64)->nullable();
            $table->timestamp('last_created_at')->nullable();
            $table->unsignedBigInteger('deleted');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SpidTransactionLog::checkpointsTable());
        Schema::dropIfExists(SpidTransactionLog::headsTable());

        Schema::table((new SpidTransactionLog)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['chain_hash']);
            $table->dropColumn(['key_id', 'previous_hash', 'chain_hash']);
        });
    }
};
