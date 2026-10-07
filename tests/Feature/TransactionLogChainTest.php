<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogChain;

mutates(TransactionLogChain::class, SpidTransactionLog::class);

function chainRow(string $transactionId = 'tx-1', array $payload = ['n' => 1]): SpidTransactionLog
{
    return SpidTransactionLog::log($transactionId, 'authentication_request', $payload, sub: 'subject-123');
}

it('starts the chain from the genesis hash and links every row to the previous one', function () {
    $first = chainRow('tx-1');
    $second = chainRow('tx-2');
    $third = chainRow('tx-3');

    expect($first->previous_hash)->toBe(TransactionLogChain::genesis())
        ->and($second->previous_hash)->toBe($first->chain_hash)
        ->and($third->previous_hash)->toBe($second->chain_hash)
        ->and($first->fresh()->chain_hash)->toBe(TransactionLogChain::link(TransactionLogChain::genesis(), $first->fresh()))
        ->and($third->chain_hash)->toMatch('/^[0-9a-f]{64}$/');

    $head = DB::table(SpidTransactionLog::headsTable())->sole();
    expect($head->head_hash)->toBe($third->chain_hash)
        ->and((int) $head->head_id)->toBe($third->getKey());
});

it('covers the search fields, the key id and the timestamp in the chain hash', function (string $column, mixed $value) {
    $row = chainRow()->fresh();
    $original = TransactionLogChain::link($row->previous_hash, $row);

    $row->setAttribute($column, $value);

    expect(TransactionLogChain::link($row->previous_hash, $row))->not->toBe($original);
})->with([
    'transaction_id' => ['transaction_id', 'tx-forged'],
    'event_type' => ['event_type', 'logout'],
    'sub' => ['sub', 'someone-else'],
    'payload_hmac' => ['payload_hmac', str_repeat('a', 64)],
    'key_id' => ['key_id', 'other'],
    'created_at' => ['created_at', now()->addMinute()],
]);

it('signs new rows with the current key and records its id', function () {
    config()->set('spid-laravel-trentino.transaction_log.keys', 'k1:secret-one');
    config()->set('spid-laravel-trentino.transaction_log.current_key', 'k1');

    $row = chainRow(payload: ['state' => 'abc'])->fresh();

    expect($row->key_id)->toBe('k1')
        ->and($row->payload_hmac)->toBe(hash_hmac('sha256', '{"state":"abc"}', 'secret-one'))
        ->and($row->verifyIntegrity())->toBeTrue();
});

it('keeps verifying rows signed with a rotated key while the key is configured', function () {
    config()->set('spid-laravel-trentino.transaction_log.keys', 'k1:secret-one,k2:secret-two');
    config()->set('spid-laravel-trentino.transaction_log.current_key', 'k1');
    $old = chainRow('tx-old');
    config()->set('spid-laravel-trentino.transaction_log.current_key', 'k2');
    $new = chainRow('tx-new');

    expect($old->fresh()->verifyIntegrity())->toBeTrue()
        ->and($new->fresh()->key_id)->toBe('k2')
        ->and($new->fresh()->verifyIntegrity())->toBeTrue();

    config()->set('spid-laravel-trentino.transaction_log.keys', 'k2:secret-two');
    expect($old->fresh()->verifyIntegrity())->toBeFalse();
});

it('verifies rows written before the upgrade with APP_KEY', function () {
    $json = '{"legacy":true}';
    DB::table('spid_transaction_logs')->insert([
        'transaction_id' => 'legacy', 'event_type' => 'logout',
        'payload' => Crypt::encryptString($json),
        'payload_hmac' => hash_hmac('sha256', $json, (string) config('app.key')),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $legacy = SpidTransactionLog::query()->sole();
    expect($legacy->key_id)->toBeNull()
        ->and($legacy->chain_hash)->toBeNull()
        ->and($legacy->verifyIntegrity())->toBeTrue();
});

it('takes the chain lock with a write before reading the chain head', function () {
    chainRow('tx-1');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    chainRow('tx-2');

    $headStatements = array_values(array_filter($statements, fn (string $sql) => str_contains($sql, 'spid_transaction_logs_heads')));
    expect($headStatements[0])->toStartWith('update');
});

it('waits for a concurrent writer instead of forking the chain', function () {
    $file = tempnam(sys_get_temp_dir(), 'spid-chain-').'.sqlite';
    touch($file);
    foreach (['writer_a', 'writer_b'] as $name) {
        config()->set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'busy_timeout' => 1]);
    }
    config()->set('database.default', 'writer_a');
    (require __DIR__.'/../../database/migrations/create_spid_transaction_logs_table.php')->up();
    (require __DIR__.'/../../database/migrations/spid_transaction_logs_add_tamper_evidence.php')->up();

    $first = SpidTransactionLog::log('tx-a', 'logout', []);

    // Another writer holds the database write lock (mid-append).
    $other = DB::connection('writer_b');
    $other->beginTransaction();
    $other->table(SpidTransactionLog::headsTable())->where('id', 1)->update(['updated_at' => now()]);

    expect(fn () => SpidTransactionLog::log('tx-blocked', 'logout', []))
        ->toThrow(QueryException::class, 'database is locked');

    $other->rollBack();
    $second = SpidTransactionLog::log('tx-b', 'logout', []);

    expect($second->previous_hash)->toBe($first->chain_hash)
        ->and(SpidTransactionLog::query()->count())->toBe(2);

    config()->set('database.default', 'testing');
    DB::purge('writer_a');
    DB::purge('writer_b');
    @unlink($file);
});

it('adds the new columns to an existing table without touching existing rows', function () {
    $migration = require __DIR__.'/../../database/migrations/spid_transaction_logs_add_tamper_evidence.php';
    $migration->down();
    $json = '{"before":"upgrade"}';
    DB::table('spid_transaction_logs')->insert([
        'transaction_id' => 'old', 'event_type' => 'logout',
        'payload' => Crypt::encryptString($json),
        'payload_hmac' => $hmac = hash_hmac('sha256', $json, (string) config('app.key')),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $row = DB::table('spid_transaction_logs')->sole();
    expect($row->payload_hmac)->toBe($hmac)
        ->and($row->key_id)->toBeNull()
        ->and($row->chain_hash)->toBeNull()
        ->and(DB::getSchemaBuilder()->hasTable(SpidTransactionLog::headsTable()))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasTable(SpidTransactionLog::checkpointsTable()))->toBeTrue();
});
