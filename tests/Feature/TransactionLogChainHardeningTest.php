<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogChain;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogKeys;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogVerifier;

mutates(TransactionLogChain::class, TransactionLogKeys::class, TransactionLogVerifier::class, SpidTransactionLog::class);

function hardenedRow(string $transactionId): SpidTransactionLog
{
    return SpidTransactionLog::log($transactionId, 'authentication_request', ['tx' => $transactionId], sub: 'subject-123');
}

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('seeds the chain head row in the migration, so the first appends never race to create it', function () {
    $head = DB::table(SpidTransactionLog::headsTable())->sole();

    expect((int) $head->id)->toBe(1)
        ->and($head->head_hash)->toBeNull()
        ->and(hardenedRow('tx-1')->previous_hash)->toBe(TransactionLogChain::genesis());
});

it('recreates a deleted chain head row, which verification then reports as a break', function () {
    hardenedRow('tx-1');
    DB::table(SpidTransactionLog::headsTable())->delete();

    $restarted = hardenedRow('tx-2');

    expect($restarted->previous_hash)->toBe(TransactionLogChain::genesis())
        ->and(DB::table(SpidTransactionLog::headsTable())->value('head_hash'))->toBe($restarted->chain_hash);
    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$restarted->id} failed verification: the previous hash does not match the preceding row.")
        ->assertFailed();
});

it('reads the chain head with a locking read', function () {
    $query = TransactionLogChain::headQuery((new SpidTransactionLog)->getConnection());

    expect($query->lock)->toBeTrue()
        ->and($query->from)->toBe(SpidTransactionLog::headsTable());
});

it('does not report missing rows when logins append rows during verification', function () {
    hardenedRow('tx-1');
    hardenedRow('tx-2');
    $appended = false;
    SpidTransactionLog::retrieved(function () use (&$appended): void {
        if (! $appended) {
            $appended = true;
            hardenedRow('during-verification');
        }
    });

    $result = app(TransactionLogVerifier::class)->verify();

    expect($appended)->toBeTrue()
        ->and($result->ok)->toBeTrue()
        ->and($result->checked)->toBe(2);
});

it('verifies app-signed rows with APP_PREVIOUS_KEYS after an APP_KEY rotation', function () {
    $oldKey = (string) config('app.key');
    $json = '{"legacy":true}';
    DB::table('spid_transaction_logs')->insert([
        'transaction_id' => 'legacy', 'event_type' => 'logout',
        'payload' => Crypt::encryptString($json),
        'payload_hmac' => hash_hmac('sha256', $json, $oldKey),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $chained = hardenedRow('tx-1');

    $newKey = 'base64:'.base64_encode(str_repeat('n', 32));
    config()->set('app.key', $newKey);
    config()->set('app.previous_keys', [$oldKey]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();
    $afterRotation = hardenedRow('tx-2');

    expect(TransactionLogKeys::secrets('app'))->toBe([$newKey, $oldKey])
        ->and(SpidTransactionLog::query()->where('transaction_id', 'legacy')->sole()->verifyIntegrity())->toBeTrue()
        ->and($chained->fresh()->verifyIntegrity())->toBeTrue()
        ->and($afterRotation->fresh()->verifyIntegrity())->toBeTrue();
    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 3 row(s), 1 legacy')->assertSuccessful();

    config()->set('app.previous_keys', []);
    expect($chained->fresh()->verifyIntegrity())->toBeFalse();
});

it('ignores empty previous keys', function () {
    config()->set('app.previous_keys', ['', null, 'old-key']);

    expect(TransactionLogKeys::secrets(null))->toBe([config('app.key'), 'old-key'])
        ->and(TransactionLogKeys::secrets('unknown'))->toBe([]);
});

it('keeps chain hashes valid when the application timezone changes', function () {
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');
    $this->travelTo('2026-03-02 10:00:00');
    hardenedRow('tx-1');
    SpidTransactionLog::log('tx-2', 'token_response', [], iat: now()->subMinute()->toImmutable(), exp: now()->addHour()->toImmutable());

    config()->set('app.timezone', 'Europe/Rome');
    date_default_timezone_set('Europe/Rome');

    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 2 row(s)')->assertSuccessful();
});
