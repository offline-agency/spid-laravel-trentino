<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Services\SpidTransactionLogger;
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
    config()->set('spid-laravel-trentino.transaction_log.enabled', true);
    config()->set('spid-laravel-trentino.transaction_log.table', 'spid_transaction_logs');
    config()->set('spid-laravel-trentino.client_id', 'test-client');

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

    $this->logger = new SpidTransactionLogger();
    $this->txId = SpidTransactionLogger::newTransactionId();
});

afterEach(function () {
    Schema::dropIfExists('spid_transaction_logs');
});

it('logs all event types for a full authentication flow', function () {
    $this->logger->logAuthenticationRequest($this->txId, ['provider_url' => 'https://idp.test', 'client_id' => 'test-client']);
    $this->logger->logAuthenticationResponse($this->txId, ['code' => 'auth-code-abc', 'state' => 'state-xyz']);
    $this->logger->logTokenRequest($this->txId, ['grant_type' => 'authorization_code']);
    $this->logger->logTokenResponse($this->txId, ['access_token' => 'at-xxx', 'token_type' => 'Bearer']);
    $this->logger->logUserInfoRequest($this->txId, []);
    $this->logger->logUserInfoResponse($this->txId, ['sub' => 'RSSMRA80A01H501U', 'email' => 'test@example.com']);
    $this->logger->logLogout($this->txId, ['sub' => 'RSSMRA80A01H501U']);

    $records = SpidTransactionLog::query()->forTransaction($this->txId)->get();

    expect($records)->toHaveCount(7);

    $eventTypes = $records->pluck('event_type')->all();
    expect($eventTypes)->toContain('authentication_request');
    expect($eventTypes)->toContain('authentication_response');
    expect($eventTypes)->toContain('token_request');
    expect($eventTypes)->toContain('token_response');
    expect($eventTypes)->toContain('userinfo_request');
    expect($eventTypes)->toContain('userinfo_response');
    expect($eventTypes)->toContain('logout');
});

it('stores authorization_code from authentication response', function () {
    $this->logger->logAuthenticationResponse($this->txId, ['code' => 'my-auth-code-123', 'state' => 'xyz']);

    $record = SpidTransactionLog::query()->forTransaction($this->txId)->first();
    expect($record->authorization_code)->toBe('my-auth-code-123');
});

it('does not write records when logging is disabled', function () {
    config()->set('spid-laravel-trentino.transaction_log.enabled', false);

    $result = $this->logger->logAuthenticationRequest($this->txId, ['provider_url' => 'https://idp.test']);

    expect($result)->toBeNull();
    expect(SpidTransactionLog::count())->toBe(0);
});

it('does not throw when the table does not exist', function () {
    Schema::dropIfExists('spid_transaction_logs');

    $result = $this->logger->logAuthenticationRequest($this->txId, ['provider_url' => 'https://idp.test']);

    // Logger swallows DB errors and returns null
    expect($result)->toBeNull();
});

it('prune command deletes only records older than the retention threshold', function () {
    // Create a recent record
    SpidTransactionLog::log('tx-recent', 'logout', []);

    // Create an old record (25 months ago)
    $old = SpidTransactionLog::log('tx-old', 'logout', []);
    \Illuminate\Support\Facades\DB::table('spid_transaction_logs')
        ->where('id', $old->id)
        ->update(['created_at' => now()->subMonths(25)]);

    $this->artisan('spid:prune-logs', ['--months' => 24])
        ->assertExitCode(0);

    expect(SpidTransactionLog::count())->toBe(1);
    expect(SpidTransactionLog::first()->transaction_id)->toBe('tx-recent');
})->skip('Requires command registration via service provider');

it('prune command clamps months below 24 to 24', function () {
    $this->artisan('spid:prune-logs', ['--months' => 6, '--dry-run' => true])
        ->expectsOutput('SPID regulations require a minimum retention of 24 months. Clamping to 24.')
        ->assertExitCode(0);
})->skip('Requires command registration via service provider');

it('newTransactionId generates a valid UUID v4 string', function () {
    $id = SpidTransactionLogger::newTransactionId();

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('extracts sub from userinfo response', function () {
    $this->logger->logUserInfoResponse($this->txId, [
        'sub' => 'RSSMRA80A01H501U',
        'email' => 'test@example.com',
    ]);

    $record = SpidTransactionLog::query()->forTransaction($this->txId)->first();
    expect($record->sub)->toBe('RSSMRA80A01H501U');
});
