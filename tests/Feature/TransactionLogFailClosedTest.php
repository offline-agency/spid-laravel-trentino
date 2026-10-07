<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Exceptions\TransactionLogUnavailable;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Services\SpidTransactionLogger;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

mutates(SpidTransactionLogger::class, TransactionLogUnavailable::class, SpidAuthenticatesUsers::class, SpidTrentino::class);

const UNAVAILABLE = 'SPID login is temporarily unavailable. Please try again later.';

/**
 * Fakes AAC, starts a login through the login route and returns the callback URI.
 */
function failClosedLogin(Closure $get): string
{
    $login = new class
    {
        public ?string $nonce = null;
    };

    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => fn () => Http::response(FakeAacProvider::tokenResponse([
            'id_token' => FakeAacProvider::idToken(['nonce' => $login->nonce]),
        ])),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response(FakeAacProvider::userInfo()),
    ]);
    Http::preventStrayRequests();

    parse_str((string) parse_url((string) $get('/spid/login')->headers->get('Location'), PHP_URL_QUERY), $query);
    $login->nonce = $query['nonce'];

    return '/spid/callback?code=auth-code&state='.$query['state'];
}

function failWritesOf(string ...$eventTypes): void
{
    SpidTransactionLog::creating(function (SpidTransactionLog $row) use ($eventTypes): void {
        if (in_array($row->event_type, $eventTypes, true)) {
            throw new RuntimeException('SQLSTATE[HY000]: insert into spid_transaction_logs values (secret-bound-value)');
        }
    });
}

/**
 * Default cache store whose forever() fails, like a cache in an unavailable database.
 */
function useCacheThatCannotStoreForever(): void
{
    Cache::extend('cannot-store-forever', fn () => Cache::repository(new class extends ArrayStore
    {
        public function forever($key, $value): bool
        {
            throw new RuntimeException('cache down');
        }
    }));
    config()->set('cache.stores.cannot-store-forever', ['driver' => 'cannot-store-forever']);
    config()->set('cache.default', 'cannot-store-forever');
}

beforeEach(fn () => config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed'));

it('defaults to fail-open', function () {
    expect(config('spid-laravel-trentino.transaction_log.fail_closed'))->toBeFalse();
});

it('keeps logging in when the log fails and fail_closed is false', function () {
    Schema::drop('spid_transaction_logs');

    $this->get(failClosedLogin(fn ($uri) => $this->get($uri)))->assertRedirect('/');

    $this->assertAuthenticated();
});

it('aborts the login route with a dedicated message when fail_closed is true', function (mixed $failClosed) {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', $failClosed);
    Schema::drop('spid_transaction_logs');
    FakeAacProvider::fake();

    $this->get('/spid/login')
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, UNAVAILABLE);
})->with(['true' => true, 'env string "true"' => 'true', 'env string "1"' => '1']);

it('aborts the callback before the code exchange when fail_closed is true', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    $callback = failClosedLogin(fn ($uri) => $this->get($uri));
    failWritesOf('authentication_response');

    $this->get($callback)
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, UNAVAILABLE);

    $this->assertGuest();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/oauth/token'));
});

it('writes nothing to the session when a later callback write fails', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    Event::fake([SpidTrentinoLoggedIn::class]);
    $callback = failClosedLogin(fn ($uri) => $this->get($uri));
    failWritesOf('userinfo_response');

    $this->get($callback)->assertRedirect('/login-failed')->assertSessionHas(SessionKeys::ERROR, UNAVAILABLE);

    $this->assertGuest();
    expect(Session::has(SessionKeys::USER))->toBeFalse()
        ->and(Session::has(SessionKeys::ACCESS_TOKEN))->toBeFalse()
        ->and(Cache::has('spid-laravel-trentino:last-login'))->toBeFalse();
    Event::assertNotDispatched(SpidTrentinoLoggedIn::class);
});

it('keeps the generic message for other login failures', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    Http::fake([FakeAacProvider::discoveryUrl() => Http::response('down', 503)]);

    $this->get('/spid/login')->assertSessionHas(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
});

it('fails a token refresh when fail_closed is true', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    Session::put(SessionKeys::REFRESH_TOKEN, 'refresh');
    FakeAacProvider::fake();
    failWritesOf('refresh_request');

    app(SpidTrentino::class)->refreshAccessToken();
})->throws(TransactionLogUnavailable::class, 'The SPID transaction log could not be written (refresh_request).');

it('never blocks logout', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    Schema::drop('spid_transaction_logs');
    Session::put(SessionKeys::USER, FakeAacProvider::userInfo());

    $this->post('/spid/logout')->assertRedirect('/');

    expect(Session::has(SessionKeys::USER))->toBeFalse();
});

it('still never logs the payload', function () {
    config()->set('spid-laravel-trentino.transaction_log.fail_closed', true);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $exception = $message->context['exception'] ?? null;
        $logged[] = $message->message.' '.json_encode($message->context).' '.($exception instanceof Throwable ? $exception->getMessage().' '.$exception->getPrevious()?->getMessage() : '');
    });
    $callback = failClosedLogin(fn ($uri) => $this->get($uri));
    failWritesOf('authentication_response');

    $this->get($callback);

    $log = implode("\n", $logged);
    expect($log)->toContain('[SPID] Transaction log write failed')
        ->and($log)->toContain('[SPID] Login aborted: the transaction log is unavailable')
        ->and($log)->not->toContain('auth-code')
        ->and($log)->not->toContain('secret-bound-value');
});

it('records the time of the last successful login', function () {
    $this->travelTo('2026-03-02 10:00:00');

    $this->get(failClosedLogin(fn ($uri) => $this->get($uri)));

    expect(Cache::get('spid-laravel-trentino:last-login'))->toBe(now()->getTimestamp());
});

it('keeps the login when the last-login marker cannot be written', function () {
    $callback = failClosedLogin(fn ($uri) => $this->get($uri));
    useCacheThatCannotStoreForever();

    $this->get($callback)->assertRedirect('/');

    $this->assertAuthenticated();
});

it('keeps writing when the write-failure marker cannot be stored', function () {
    useCacheThatCannotStoreForever();
    failWritesOf('logout');

    expect(app(SpidTransactionLogger::class)->logLogout('tx', ['sub' => 'subject']))->toBeNull();
});
