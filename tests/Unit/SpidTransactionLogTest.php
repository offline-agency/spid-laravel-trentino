<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    config()->set('spid-laravel-trentino.transaction_log.table', 'spid_transaction_logs');

    Schema::create('spid_transaction_logs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('transaction_id', 64)->index();
        $table->string('event_type', 50);
        $table->string('authorization_code', 512)->nullable();
        $table->string('client_id', 255)->nullable();
        $table->string('jti', 255)->nullable();
        $table->string('iss', 512)->nullable();
        $table->string('sub', 255)->nullable();
        $table->timestamp('iat')->nullable();
        $table->timestamp('exp')->nullable();
        $table->text('payload');
        $table->string('payload_hmac', 128);
        $table->string('ip_address', 45)->nullable();
        $table->string('user_agent', 512)->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('spid_transaction_logs');
});

it('creates a record with correct fields', function () {
    $txId = 'test-transaction-id-001';
    $record = SpidTransactionLog::log(
        transactionId: $txId,
        eventType: 'authentication_request',
        payload: ['provider_url' => 'https://example.com', 'client_id' => 'my-client'],
        clientId: 'my-client',
        iss: 'https://example.com',
    );

    expect($record->id)->toBeInt();
    expect($record->transaction_id)->toBe($txId);
    expect($record->event_type)->toBe('authentication_request');
    expect($record->client_id)->toBe('my-client');
    expect($record->iss)->toBe('https://example.com');
    expect($record->payload_hmac)->toBeString();
});

it('stores payload as encrypted text in the database', function () {
    $plainPayload = ['secret_data' => 'very-sensitive-value-12345'];

    $record = SpidTransactionLog::log(
        transactionId: 'tx-encrypt-test',
        eventType: 'token_response',
        payload: $plainPayload,
    );

    // The decrypted payload via the model should contain the original data
    $retrieved = SpidTransactionLog::find($record->id);
    $decoded = json_decode($retrieved->payload, true);
    expect($decoded['secret_data'])->toBe('very-sensitive-value-12345');

    // The raw database value should NOT contain the plaintext
    $rawRow = \Illuminate\Support\Facades\DB::table('spid_transaction_logs')
        ->where('id', $record->id)
        ->value('payload');

    expect($rawRow)->not->toContain('very-sensitive-value-12345');
});

it('verifyIntegrity returns true for unmodified records', function () {
    $record = SpidTransactionLog::log(
        transactionId: 'tx-integrity-ok',
        eventType: 'userinfo_response',
        payload: ['sub' => 'RSSMRA80A01H501U'],
    );

    $retrieved = SpidTransactionLog::find($record->id);
    expect($retrieved->verifyIntegrity())->toBeTrue();
});

it('verifyIntegrity returns false when payload_hmac is tampered with', function () {
    $record = SpidTransactionLog::log(
        transactionId: 'tx-integrity-fail',
        eventType: 'userinfo_response',
        payload: ['sub' => 'RSSMRA80A01H501U'],
    );

    // Tamper with the HMAC directly in the DB
    \Illuminate\Support\Facades\DB::table('spid_transaction_logs')
        ->where('id', $record->id)
        ->update(['payload_hmac' => str_repeat('0', 64)]);

    $retrieved = SpidTransactionLog::find($record->id);
    expect($retrieved->verifyIntegrity())->toBeFalse();
});

it('scopeExpired filters records older than N months', function () {
    // Recent record - should NOT be expired
    $recent = SpidTransactionLog::log(
        transactionId: 'tx-recent',
        eventType: 'logout',
        payload: [],
    );

    // Manually set created_at to 25 months ago for an old record
    $old = SpidTransactionLog::log(
        transactionId: 'tx-old',
        eventType: 'logout',
        payload: [],
    );
    \Illuminate\Support\Facades\DB::table('spid_transaction_logs')
        ->where('id', $old->id)
        ->update(['created_at' => now()->subMonths(25)]);

    $expired = SpidTransactionLog::query()->expired(24)->get();

    expect($expired)->toHaveCount(1);
    expect($expired->first()->transaction_id)->toBe('tx-old');
});

it('throws InvalidArgumentException for invalid event type', function () {
    SpidTransactionLog::log(
        transactionId: 'tx-bad-type',
        eventType: 'invalid_event_xyz',
        payload: [],
    );
})->throws(\InvalidArgumentException::class);

it('payload_hmac is a 64-character hex string', function () {
    $record = SpidTransactionLog::log(
        transactionId: 'tx-hmac-length',
        eventType: 'authentication_request',
        payload: ['key' => 'value'],
    );

    expect($record->payload_hmac)->toHaveLength(64);
    expect(ctype_xdigit($record->payload_hmac))->toBeTrue();
});

it('scopeForTransaction returns only records for given transaction id', function () {
    SpidTransactionLog::log('tx-A', 'authentication_request', []);
    SpidTransactionLog::log('tx-A', 'authentication_response', []);
    SpidTransactionLog::log('tx-B', 'authentication_request', []);

    $records = SpidTransactionLog::query()->forTransaction('tx-A')->get();

    expect($records)->toHaveCount(2);
    expect($records->every(fn ($r) => $r->transaction_id === 'tx-A'))->toBeTrue();
});
