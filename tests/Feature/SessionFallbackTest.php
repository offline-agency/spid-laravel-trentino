<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(LaravelOpenIDConnectClient::class);

const BROWSER_IP = '203.0.113.10';
const BROWSER_UA = 'Mozilla/5.0 (SPID test browser)';

function fallbackClient(bool $sessionFallback): LaravelOpenIDConnectClient
{
    $client = new LaravelOpenIDConnectClient(
        FakeAacProvider::ISSUER,
        FakeAacProvider::CLIENT_ID,
        FakeAacProvider::CLIENT_SECRET,
        3600,
        $sessionFallback,
    );
    $client->setRedirectURL('https://app.test/spid/callback');
    $client->setCodeChallengeMethod('S256');

    return $client;
}

/** @param array<string, string> $query */
function browserRequest(array $query = [], string $ip = BROWSER_IP, string $userAgent = BROWSER_UA): void
{
    app()->instance('request', Request::create('/spid/callback', 'GET', $query, [], [], [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $userAgent,
    ]));
    Facade::clearResolvedInstance('request');
}

/**
 * Fakes AAC so the ID token carries the nonce of the login that is started next.
 *
 * @return object{nonce: ?string}
 */
function fakeAacForNextLogin(): object
{
    $login = new class
    {
        public ?string $nonce = null;
    };

    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => fn () => Http::response(
            FakeAacProvider::tokenResponse(['id_token' => FakeAacProvider::idToken(['nonce' => $login->nonce])]),
        ),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response(FakeAacProvider::userInfo()),
    ]);
    Http::preventStrayRequests();

    return $login;
}

/**
 * Runs the login redirect and returns its state, remembering the nonce for the fake AAC.
 *
 * @param  object{nonce: ?string}  $login
 */
function startLogin(LaravelOpenIDConnectClient $client, object $login): string
{
    browserRequest();
    parse_str((string) parse_url($client->authorizationRedirect()->getTargetUrl(), PHP_URL_QUERY), $query);
    $login->nonce = $query['nonce'];

    return $query['state'];
}

it('recovers a pending login from the cache when the session cookie was lost', function () {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(true), $login);
    $verifier = Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });

    Session::flush(); // the proxy dropped the cookie: the callback arrives with an empty session
    browserRequest(['code' => 'auth-code', 'state' => $state]);

    expect(fallbackClient(true)->authenticate())->toBeTrue()
        ->and($logged)->toContain('[SPID] Session fallback used');
    Http::assertSent(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/oauth/token'
        && $request['code_verifier'] === $verifier);
});

it('can be used only once', function () {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(true), $login);
    Session::flush();
    browserRequest(['code' => 'auth-code', 'state' => $state]);
    fallbackClient(true)->authenticate();

    Session::flush();

    expect(fn () => fallbackClient(true)->authenticate())->toThrow(OpenIDConnectClientException::class);
});

it('is disabled by default', function () {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(false), $login);
    Session::flush();
    browserRequest(['code' => 'auth-code', 'state' => $state]);

    expect(fn () => fallbackClient(true)->authenticate())->toThrow(OpenIDConnectClientException::class);
});

it('refuses the fallback to a different client', function (string $ip, string $userAgent) {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(true), $login);
    Session::flush();
    browserRequest(['code' => 'auth-code', 'state' => $state], $ip, $userAgent);

    expect(fn () => fallbackClient(true)->authenticate())->toThrow(OpenIDConnectClientException::class);
})->with([
    'other IP address' => ['198.51.100.7', BROWSER_UA],
    'other user agent' => [BROWSER_IP, 'curl/8.0'],
]);

it('expires ten minutes after the login started', function () {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(true), $login);
    Session::flush();
    $this->travel(601)->seconds();
    browserRequest(['code' => 'auth-code', 'state' => $state]);

    expect(fn () => fallbackClient(true)->authenticate())->toThrow(OpenIDConnectClientException::class);
});

it('uses the session and not the cache when the cookie survived', function () {
    $login = fakeAacForNextLogin();
    $state = startLogin(fallbackClient(true), $login);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });
    browserRequest(['code' => 'auth-code', 'state' => $state]);

    expect(fallbackClient(true)->authenticate())->toBeTrue()
        ->and($logged)->not->toContain('[SPID] Session fallback used');
});

it('ignores a callback without state', function () {
    fakeAacForNextLogin();
    browserRequest(['code' => 'auth-code']);

    expect(fn () => fallbackClient(true)->authenticate())->toThrow(OpenIDConnectClientException::class, 'Unable to determine state');
});

it('completes a real HTTP login after the session cookie was lost', function () {
    config()->set('spid-laravel-trentino.session_fallback', true);
    $login = fakeAacForNextLogin();

    $redirect = $this->get('/spid/login')->headers->get('Location');
    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
    $login->nonce = $query['nonce'];

    Session::flush();

    $this->get('/spid/callback?code=auth-code&state='.$query['state'])->assertRedirect('/');
    $this->assertAuthenticated();
});
