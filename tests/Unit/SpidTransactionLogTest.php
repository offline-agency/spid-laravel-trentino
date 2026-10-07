<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

mutates(SpidTransactionLog::class);

function logRecord(string $transactionId = 'tx-1', array $payload = ['state' => 'abc']): SpidTransactionLog
{
    return SpidTransactionLog::log(
        transactionId: $transactionId,
        eventType: 'token_response',
        payload: $payload,
        authorizationCode: 'auth-code',
        clientId: 'test-client',
        jti: 'jti-1',
        iss: 'https://aac.test',
        sub: 'subject-123',
        iat: CarbonImmutable::parse('2026-01-01T12:00:00+00:00'),
        exp: CarbonImmutable::parse('2026-01-01T12:10:00+00:00'),
        ipAddress: '203.0.113.10',
        userAgent: 'Mozilla/5.0',
    );
}

it('creates a record with the indexed fields', function () {
    $record = logRecord()->fresh();

    expect($record->transaction_id)->toBe('tx-1')
        ->and($record->event_type)->toBe('token_response')
        ->and($record->authorization_code)->toBe('auth-code')
        ->and($record->client_id)->toBe('test-client')
        ->and($record->jti)->toBe('jti-1')
        ->and($record->iss)->toBe('https://aac.test')
        ->and($record->sub)->toBe('subject-123')
        ->and($record->iat?->toIso8601String())->toBe('2026-01-01T12:00:00+00:00')
        ->and($record->exp?->toIso8601String())->toBe('2026-01-01T12:10:00+00:00')
        ->and($record->ip_address)->toBe('203.0.113.10')
        ->and($record->user_agent)->toBe('Mozilla/5.0')
        ->and($record->payloadData())->toBe(['state' => 'abc']);
});

it('stores the payload encrypted at rest', function () {
    logRecord(payload: ['secret-ish' => 'plain-value']);

    $raw = DB::table('spid_transaction_logs')->value('payload');

    expect($raw)->not->toContain('plain-value')
        ->and(Crypt::decryptString($raw))->toBe('{"secret-ish":"plain-value"}');
});

it('signs the payload with a 64-character HMAC-SHA256', function () {
    expect(logRecord()->payload_hmac)->toMatch('/^[0-9a-f]{64}$/');
});

it('verifies the integrity of untouched records', function () {
    expect(logRecord()->fresh()->verifyIntegrity())->toBeTrue();
});

it('detects tampering', function (string $column, Closure $value) {
    $record = logRecord();
    DB::table('spid_transaction_logs')->where('id', $record->getKey())->update([$column => $value()]);

    expect($record->fresh()->verifyIntegrity())->toBeFalse();
})->with([
    'payload replaced' => ['payload', fn () => Crypt::encryptString('{"state":"forged"}')],
    'hmac replaced' => ['payload_hmac', fn () => str_repeat('0', 64)],
]);

it('rejects unknown event types', function () {
    expect(fn () => SpidTransactionLog::log('tx-1', 'coffee_break', []))
        ->toThrow(InvalidArgumentException::class, 'Invalid SPID transaction event type [coffee_break]');
});

it('filters records by transaction', function () {
    logRecord('tx-1');
    logRecord('tx-2');
    logRecord('tx-1');

    expect(SpidTransactionLog::query()->forTransaction('tx-1')->count())->toBe(2);
});

it('finds records older than the retention period', function () {
    $this->travelTo(CarbonImmutable::parse('2024-01-01T00:00:00+00:00'));
    logRecord('old');
    $this->travelTo(CarbonImmutable::parse('2026-01-02T00:00:00+00:00'));
    logRecord('new');

    expect(SpidTransactionLog::query()->expired(24)->pluck('transaction_id')->all())->toBe(['old'])
        ->and(SpidTransactionLog::query()->expired(25)->count())->toBe(0);
});

it('uses the configured table name', function (mixed $configured, string $expected) {
    config()->set('spid-laravel-trentino.transaction_log.table', $configured);

    expect((new SpidTransactionLog)->getTable())->toBe($expected);
})->with([
    'custom' => ['audit_spid', 'audit_spid'],
    'empty falls back' => ['', 'spid_transaction_logs'],
    'missing falls back' => [null, 'spid_transaction_logs'],
]);

it('returns an empty payload when the stored JSON is not an object', function () {
    $record = logRecord();
    DB::table('spid_transaction_logs')->where('id', $record->getKey())->update(['payload' => Crypt::encryptString('"text"')]);

    expect($record->fresh()->payloadData())->toBe([]);
});
