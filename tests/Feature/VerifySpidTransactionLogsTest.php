<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\VerifySpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogVerifier;
use OfflineAgency\SpidLaravelTrentino\Support\VerificationResult;

mutates(TransactionLogVerifier::class, VerificationResult::class, VerifySpidTransactionLogs::class);

function verifiedRow(string $transactionId, ?string $date = null): SpidTransactionLog
{
    if ($date !== null) {
        test()->travelTo(CarbonImmutable::parse($date));
    }

    return SpidTransactionLog::log($transactionId, 'authentication_request', ['tx' => $transactionId], sub: 'subject-123');
}

function legacyRow(string $transactionId, string $json = '{"legacy":true}'): int
{
    return (int) DB::table('spid_transaction_logs')->insertGetId([
        'transaction_id' => $transactionId, 'event_type' => 'logout',
        'payload' => Crypt::encryptString($json),
        'payload_hmac' => hash_hmac('sha256', $json, (string) config('app.key')),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('passes on an intact chain', function () {
    verifiedRow('tx-1');
    verifiedRow('tx-2');
    verifiedRow('tx-3');

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain('Verified 3 row(s), 0 legacy')
        ->assertSuccessful();
});

it('passes on an empty log', function () {
    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 0 row(s)')->assertSuccessful();
});

it('reports a changed payload', function () {
    verifiedRow('tx-1');
    $row = verifiedRow('tx-2');
    $row->payload = '{"tx":"forged"}';
    $row->save();

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$row->getKey()} failed verification: the payload does not match its HMAC.")
        ->assertFailed();
});

it('reports a payload that cannot be decrypted', function () {
    $row = verifiedRow('tx-1');
    DB::table('spid_transaction_logs')->where('id', $row->getKey())->update(['payload' => 'not-a-ciphertext']);

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$row->getKey()} failed verification: the payload cannot be decrypted.")
        ->assertFailed();
});

it('reports a row signed with an unknown key', function () {
    $row = verifiedRow('tx-1');
    DB::table('spid_transaction_logs')->where('id', $row->getKey())->update(['key_id' => 'retired']);

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$row->getKey()} failed verification: the HMAC key [retired] is not configured.")
        ->assertFailed();
});

it('reports a changed search field', function () {
    $row = verifiedRow('tx-1');
    DB::table('spid_transaction_logs')->where('id', $row->getKey())->update(['sub' => 'someone-else']);

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$row->getKey()} failed verification: the chain hash does not match the row.")
        ->assertFailed();
});

it('reports a deleted row', function () {
    verifiedRow('tx-1');
    $deleted = verifiedRow('tx-2');
    $next = verifiedRow('tx-3');
    $deleted->delete();

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$next->getKey()} failed verification: the previous hash does not match the preceding row.")
        ->assertFailed();
});

it('reports deleted rows at the end of the chain', function () {
    $last = verifiedRow('tx-1');
    verifiedRow('tx-2')->delete();

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain('the newest rows are missing: the chain head does not match the last row.')
        ->assertFailed();

    expect(app(TransactionLogVerifier::class)->verify()->brokenAt)->toBe($last->getKey() + 1);
});

it('reports an inserted unchained row after the chain started', function () {
    verifiedRow('tx-1');
    $inserted = legacyRow('inserted');

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$inserted} failed verification: the row is not chained.")
        ->assertFailed();
});

it('treats rows written before the upgrade as legacy', function () {
    legacyRow('legacy-1');
    legacyRow('legacy-2');
    verifiedRow('tx-1');
    verifiedRow('tx-2');

    $result = app(TransactionLogVerifier::class)->verify();

    expect($result)->toEqual(new VerificationResult(true, 4, 2, null, null));
    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 4 row(s), 2 legacy')->assertSuccessful();
});

it('still checks the HMAC of legacy rows', function () {
    $legacy = legacyRow('legacy-1');
    DB::table('spid_transaction_logs')->where('id', $legacy)->update(['payload_hmac' => str_repeat('0', 64)]);
    verifiedRow('tx-1');

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$legacy} failed verification: the payload does not match its HMAC.")
        ->assertFailed();
});

it('verifies the chain from the last prune checkpoint', function () {
    verifiedRow('old-1', '2023-01-01');
    verifiedRow('old-2', '2023-02-01');
    verifiedRow('new-1', '2026-01-01');
    verifiedRow('new-2', '2026-01-02');

    $this->artisan('spid:prune-logs')->expectsOutputToContain('Deleted 2')->assertSuccessful();
    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 2 row(s)')->assertSuccessful();
});

it('reports a row deleted right after the prune checkpoint', function () {
    verifiedRow('old-1', '2023-01-01');
    $first = verifiedRow('new-1', '2026-01-01');
    $second = verifiedRow('new-2', '2026-01-02');

    $this->artisan('spid:prune-logs')->assertSuccessful();
    $first->delete();

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$second->getKey()} failed verification: the previous hash does not match the preceding row.")
        ->assertFailed();
});

it('anchors on genesis after pruning only legacy rows', function () {
    $this->travelTo(CarbonImmutable::parse('2023-01-01'));
    legacyRow('legacy-1');
    verifiedRow('new-1', '2026-01-01');

    $this->artisan('spid:prune-logs')->expectsOutputToContain('Deleted 1')->assertSuccessful();
    $this->artisan('spid:verify-logs')->expectsOutputToContain('Verified 1 row(s), 0 legacy')->assertSuccessful();
});

it('limits verification to --from/--to', function () {
    $tampered = verifiedRow('day-1', '2026-03-01 10:00');
    verifiedRow('day-2', '2026-03-02 10:00');
    verifiedRow('day-3', '2026-03-03 10:00');
    DB::table('spid_transaction_logs')->where('id', $tampered->getKey())->update(['sub' => 'someone-else']);

    $this->artisan('spid:verify-logs', ['--from' => '2026-03-02'])
        ->expectsOutputToContain('Verified 2 row(s)')
        ->assertSuccessful();

    $this->artisan('spid:verify-logs', ['--from' => '2026-03-02', '--to' => '2026-03-02'])
        ->expectsOutputToContain('Verified 1 row(s)')
        ->assertSuccessful();

    $this->artisan('spid:verify-logs', ['--to' => '2026-03-01'])
        ->expectsOutputToContain("Row #{$tampered->getKey()} failed verification")
        ->assertFailed();

    $this->artisan('spid:verify-logs', ['--from' => '2026-04-01'])
        ->expectsOutputToContain('Verified 0 row(s)')
        ->assertSuccessful();
});

it('checks the first row of a range against the row before it', function () {
    verifiedRow('day-1', '2026-03-01 10:00');
    $deleted = verifiedRow('day-1b', '2026-03-01 11:00');
    $first = verifiedRow('day-2', '2026-03-02 10:00');
    $deleted->delete();

    $this->artisan('spid:verify-logs', ['--from' => '2026-03-02'])
        ->expectsOutputToContain("Row #{$first->getKey()} failed verification: the previous hash does not match the preceding row.")
        ->assertFailed();
});

it('exits non-zero and names the first broken row', function () {
    verifiedRow('tx-1');
    $first = verifiedRow('tx-2');
    $second = verifiedRow('tx-3');
    DB::table('spid_transaction_logs')->whereIn('id', [$first->getKey(), $second->getKey()])->update(['sub' => 'someone-else']);

    $this->artisan('spid:verify-logs')
        ->expectsOutputToContain("Row #{$first->getKey()} failed verification")
        ->doesntExpectOutputToContain("Row #{$second->getKey()}")
        ->assertExitCode(1);
});

it('rejects invalid dates', function (array $options, string $message) {
    $this->artisan('spid:verify-logs', $options)->expectsOutputToContain($message)->assertExitCode(2);
})->with([
    'from' => [['--from' => 'yesterday'], 'Invalid --from date [yesterday]; expected YYYY-MM-DD.'],
    'to' => [['--to' => '2026-13-01'], 'Invalid --to date [2026-13-01]; expected YYYY-MM-DD.'],
    'reversed' => [['--from' => '2026-03-02', '--to' => '2026-03-01'], '--from must not be after --to.'],
]);
