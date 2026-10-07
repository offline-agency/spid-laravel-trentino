<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Services\SpidTransactionLogger;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(SpidTransactionLogger::class, SpidTrentino::class);

/**
 * Fakes AAC so that the ID token carries the nonce of the login started next.
 *
 * @return object{nonce: ?string}
 */
function fakeAacForLoggedLogin(array $token = []): object
{
    $login = new class
    {
        public ?string $nonce = null;
    };

    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => fn () => Http::response(FakeAacProvider::tokenResponse(array_merge([
            'id_token' => FakeAacProvider::idToken(['nonce' => $login->nonce, 'jti' => 'jti-123']),
        ], $token))),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response(FakeAacProvider::userInfo()),
    ]);
    Http::preventStrayRequests();

    return $login;
}

/** @return array{state: string} */
function loggedLogin(object $login, Closure $get): array
{
    parse_str((string) parse_url((string) $get('/spid/login')->headers->get('Location'), PHP_URL_QUERY), $query);
    $login->nonce = $query['nonce'];

    return $query;
}

/** @return array<string, SpidTransactionLog> */
function recordsByEvent(): array
{
    return SpidTransactionLog::query()->orderBy('id')->get()->keyBy('event_type')->all();
}

it('logs every OIDC message of a login under one transaction id', function () {
    $login = fakeAacForLoggedLogin();
    $query = loggedLogin($login, fn ($uri) => $this->get($uri));
    $transactionId = session(SessionKeys::TRANSACTION_ID);

    $this->get('/spid/callback?code=auth-code&state='.$query['state'])->assertRedirect('/');

    $records = recordsByEvent();
    expect(array_keys($records))->toBe([
        'authentication_request', 'authentication_response', 'token_request',
        'token_response', 'userinfo_request', 'userinfo_response',
    ])
        ->and(collect($records)->pluck('transaction_id')->unique()->all())->toBe([$transactionId])
        ->and($transactionId)->toMatch('/^[0-9a-f-]{36}$/');

    expect($records['authentication_request']->payloadData())->toMatchArray([
        'authorization_endpoint' => FakeAacProvider::ISSUER.'/oauth/authorize',
        'client_id' => FakeAacProvider::CLIENT_ID,
        'state' => $query['state'],
        'nonce' => $query['nonce'],
        'code_challenge_method' => 'S256',
    ]);
    expect($records['authentication_response']->authorization_code)->toBe('auth-code')
        ->and($records['authentication_response']->payloadData())->toBe(['code' => 'auth-code', 'state' => $query['state']]);
    expect($records['token_request']->payloadData())->toMatchArray(['grant_type' => 'authorization_code', 'code' => 'auth-code']);

    $tokenResponse = $records['token_response'];
    $claims = json_decode(base64_decode(strtr(explode('.', $tokenResponse->payloadData()['id_token'])[1], '-_', '+/')), true);
    expect($tokenResponse->jti)->toBe('jti-123')
        ->and($tokenResponse->iss)->toBe(FakeAacProvider::ISSUER)
        ->and($tokenResponse->sub)->toBe('subject-123')
        ->and($tokenResponse->iat?->getTimestamp())->toBe($claims['iat'])
        ->and($tokenResponse->exp?->getTimestamp())->toBe($claims['exp'])
        ->and($tokenResponse->payloadData()['access_token'])->toBe('sha256:'.hash('sha256', FakeAacProvider::ACCESS_TOKEN))
        ->and($tokenResponse->payloadData()['refresh_token'])->toBe('sha256:'.hash('sha256', FakeAacProvider::REFRESH_TOKEN))
        ->and($tokenResponse->payloadData()['id_token'])->toStartWith('eyJ');

    expect($records['userinfo_response']->sub)->toBe('subject-123')
        ->and($records['userinfo_response']->payloadData()['enti-codicefiscale']['fiscalCode'])->toBe(FakeAacProvider::FISCAL_CODE)
        ->and($records['userinfo_response']->ip_address)->toBe('127.0.0.1')
        ->and($records['userinfo_response']->user_agent)->toBe('Symfony')
        ->and($records['userinfo_response']->verifyIntegrity())->toBeTrue();
});

it('never stores the client secret or raw access and refresh tokens', function () {
    $login = fakeAacForLoggedLogin(['client_secret' => FakeAacProvider::CLIENT_SECRET]);
    $query = loggedLogin($login, fn ($uri) => $this->get($uri));
    $this->get('/spid/callback?code=auth-code&state='.$query['state']);
    app(SpidTrentino::class)->refreshAccessToken();

    $payloads = SpidTransactionLog::query()->get()->map->payload->implode("\n");

    expect($payloads)->not->toContain(FakeAacProvider::CLIENT_SECRET)
        ->and($payloads)->not->toContain(FakeAacProvider::ACCESS_TOKEN)
        ->and($payloads)->not->toContain(FakeAacProvider::REFRESH_TOKEN);
});

it('logs the authentication response even when the callback fails', function () {
    fakeAacForLoggedLogin();
    $this->get('/spid/login');

    $this->get('/spid/callback?error=access_denied&error_description=Cancelled')->assertRedirect('/');

    expect(array_keys(recordsByEvent()))->toBe(['authentication_request', 'authentication_response'])
        ->and(recordsByEvent()['authentication_response']->payloadData())->toBe(['error' => 'access_denied', 'error_description' => 'Cancelled']);
});

it('logs token refreshes and logouts', function () {
    $login = fakeAacForLoggedLogin();
    $query = loggedLogin($login, fn ($uri) => $this->get($uri));
    $this->get('/spid/callback?code=auth-code&state='.$query['state']);
    $transactionId = session(SessionKeys::TRANSACTION_ID);

    app(SpidTrentino::class)->refreshAccessToken();
    $this->post('/spid/logout');

    $records = recordsByEvent();
    expect($records['refresh_request']->payloadData())->toBe(['grant_type' => 'refresh_token', 'client_id' => FakeAacProvider::CLIENT_ID])
        ->and($records['refresh_response']->payloadData()['access_token'])->toStartWith('sha256:')
        ->and($records['refresh_response']->transaction_id)->toBe($transactionId)
        ->and($records['logout']->sub)->toBe('subject-123')
        ->and($records['logout']->transaction_id)->toBe($transactionId);
});

it('starts a new transaction id for events without a login in the session', function () {
    Session::put(SessionKeys::REFRESH_TOKEN, 'refresh');
    fakeAacForLoggedLogin();

    app(SpidTrentino::class)->refreshAccessToken();

    expect(SpidTransactionLog::query()->pluck('transaction_id')->unique())->toHaveCount(1)
        ->and(SpidTransactionLog::query()->value('transaction_id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('does not write records when the transaction log is disabled', function (mixed $enabled) {
    config()->set('spid-laravel-trentino.transaction_log.enabled', $enabled);
    $login = fakeAacForLoggedLogin();
    $query = loggedLogin($login, fn ($uri) => $this->get($uri));

    $this->get('/spid/callback?code=auth-code&state='.$query['state'])->assertRedirect('/');

    expect(SpidTransactionLog::query()->count())->toBe(0);
    $this->assertAuthenticated();
})->with(['false' => false, 'env string "false"' => 'false', 'env string "0"' => '0']);

it('keeps the login working when the log cannot be written, without leaking the payload into the error log', function () {
    Schema::drop('spid_transaction_logs');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    $login = fakeAacForLoggedLogin();
    $query = loggedLogin($login, fn ($uri) => $this->get($uri));

    $this->get('/spid/callback?code=auth-code&state='.$query['state'])->assertRedirect('/');

    $this->assertAuthenticated();
    $failures = array_values(array_filter($logged, fn (string $line) => str_starts_with($line, '[SPID] Transaction log write failed')));
    expect($failures)->not->toBeEmpty()
        ->and(implode("\n", $failures))->not->toContain('auth-code')
        ->and(implode("\n", $failures))->toContain('QueryException');
});

it('generates UUID transaction ids', function () {
    expect(SpidTransactionLogger::newTransactionId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(SpidTransactionLogger::newTransactionId())->not->toBe(SpidTransactionLogger::newTransactionId());
});

it('ignores ID tokens it cannot decode', function (string $idToken) {
    app(SpidTransactionLogger::class)->logTokenResponse('tx-1', ['id_token' => $idToken]);

    $record = SpidTransactionLog::query()->sole();
    expect($record->jti)->toBeNull()->and($record->sub)->toBeNull()->and($record->iat)->toBeNull();
})->with(['not a JWT' => 'abc', 'bad base64' => 'a.!!!.c', 'not JSON' => 'a.'.base64_encode('text').'.c']);

it('prunes records older than the retention period', function () {
    $this->travelTo(CarbonImmutable::parse('2023-12-01T00:00:00+00:00'));
    app(SpidTransactionLogger::class)->logLogout('old', ['sub' => 'x']);
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00+00:00'));
    app(SpidTransactionLogger::class)->logLogout('new', ['sub' => 'x']);

    $this->artisan('spid:prune-logs', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 record(s) older than 24 months')
        ->assertSuccessful();
    expect(SpidTransactionLog::query()->count())->toBe(2);

    $this->artisan('spid:prune-logs')->expectsOutputToContain('Deleted 1 SPID transaction log record(s) older than 24 months')->assertSuccessful();
    expect(SpidTransactionLog::query()->pluck('transaction_id')->all())->toBe(['new']);
});

it('never prunes below 24 months', function (array $options, mixed $configured) {
    config()->set('spid-laravel-trentino.transaction_log.retention_months', $configured);

    $this->artisan('spid:prune-logs', $options + ['--dry-run' => true])
        ->expectsOutputToContain('SPID regulations require a minimum retention of 24 months')
        ->expectsOutputToContain('older than 24 months')
        ->assertSuccessful();
})->with([
    '--months below the floor' => [['--months' => '6'], 24],
    'configured below the floor' => [[], 12],
]);

it('uses the configured retention period, overridable with --months', function (array $options, mixed $configured, int $expected) {
    config()->set('spid-laravel-trentino.transaction_log.retention_months', $configured);

    $this->artisan('spid:prune-logs', $options + ['--dry-run' => true])
        ->expectsOutputToContain("older than {$expected} months")
        ->assertSuccessful();
})->with([
    'config' => [[], 36, 36],
    'config as env string' => [[], '30', 30],
    'option wins' => [['--months' => '48'], 36, 48],
    'invalid config falls back to 24' => [[], 'forever', 24],
]);
