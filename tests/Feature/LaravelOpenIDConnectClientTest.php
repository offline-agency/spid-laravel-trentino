<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(LaravelOpenIDConnectClient::class);

function oidcClient(int $cacheTtl = 3600): LaravelOpenIDConnectClient
{
    $client = new LaravelOpenIDConnectClient(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID, FakeAacProvider::CLIENT_SECRET, $cacheTtl);
    $client->setRedirectURL('https://app.test/spid/callback');
    $client->setCodeChallengeMethod('S256');

    return $client;
}

/** @return array<string, string> */
function callbackParameters(): array
{
    return ['code' => 'auth-code', 'state' => FakeAacProvider::STATE];
}

it('builds the authorization redirect and keeps state, nonce and PKCE verifier in the Laravel session', function () {
    FakeAacProvider::fake();

    $response = oidcClient()->authorizationRedirect();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toStartWith(FakeAacProvider::ISSUER.'/oauth/authorize?');

    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
    $verifier = Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier');

    expect($query['state'])->toBe(Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_state'))
        ->and($query['nonce'])->toBe(Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_nonce'))
        ->and($query['client_id'])->toBe(FakeAacProvider::CLIENT_ID)
        ->and($query['redirect_uri'])->toBe('https://app.test/spid/callback')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and(session_status())->toBe(PHP_SESSION_NONE)
        ->and($_SESSION ?? [])->toBe([]);
});

it('fails when the authorization request does not end in a redirect', function () {
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function authenticateWith(array $parameters): bool
        {
            return true;
        }
    };

    expect(fn () => $client->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'The authorization request did not produce a redirect.');
});

it('turns redirects into HttpResponseException instead of calling exit', function () {
    try {
        oidcClient()->redirect('https://aac.test/somewhere');
        $this->fail('redirect() returned');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->headers->get('Location'))->toBe('https://aac.test/somewhere');
    }
});

it('completes the code flow with a valid signed ID token', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    $client = oidcClient();

    expect($client->authenticateWith(callbackParameters()))->toBeTrue()
        ->and($client->getAccessToken())->toBe(FakeAacProvider::ACCESS_TOKEN)
        ->and($client->getVerifiedClaims('sub'))->toBe('subject-123')
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_state'))->toBeFalse()
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_nonce'))->toBeFalse()
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier'))->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/oauth/token'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === FakeAacProvider::CODE_VERIFIER
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret')));
});

it('reads the callback parameters from the current Laravel request', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    app()->instance('request', Request::create('/spid/callback', 'GET', callbackParameters()));
    Facade::clearResolvedInstance('request');

    expect(oidcClient()->authenticate())->toBeTrue();
});

it('restores the $_REQUEST superglobal after authenticating', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    $_REQUEST = ['untouched' => 'yes'];

    oidcClient()->authenticateWith(callbackParameters());

    expect($_REQUEST)->toBe(['untouched' => 'yes']);
    $_REQUEST = [];
});

it('rejects ID tokens that fail verification', function (array $claims, string $signingKey) {
    FakeAacProvider::fake(['id_token' => FakeAacProvider::idToken($claims, $signingKey)]);
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient()->authenticateWith(callbackParameters()))
        ->toThrow(OpenIDConnectClientException::class);
})->with([
    'expired' => [['exp' => time() - 3600], 'trusted'],
    'missing exp' => [['exp' => null], 'trusted'],
    'wrong aud (string)' => [['aud' => 'another-client'], 'trusted'],
    'wrong aud (array)' => [['aud' => ['another-client', 'third-client']], 'trusted'],
    'missing aud' => [['aud' => null], 'trusted'],
    'wrong iss' => [['iss' => 'https://evil.test'], 'trusted'],
    'missing iss' => [['iss' => null], 'trusted'],
    'missing sub' => [['sub' => null], 'trusted'],
    'nonce mismatch' => [['nonce' => 'other-nonce'], 'trusted'],
    'bad signature' => [[], 'attacker'],
]);

it('rejects malformed token endpoint responses with an OpenIDConnectClientException', function (Closure $response) {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => $response(),
    ]);
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient()->authenticateWith(callbackParameters()))
        ->toThrow(OpenIDConnectClientException::class);
})->with([
    'gateway error with HTML body' => fn () => fn () => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html']),
    '200 with non-JSON body' => fn () => fn () => Http::response('not json', 200),
    'JSON without access_token' => fn () => fn () => Http::response(FakeAacProvider::tokenResponse(['access_token' => null])),
    'JSON without id_token' => fn () => fn () => Http::response(FakeAacProvider::tokenResponse(['id_token' => null])),
    'non-string id_token' => fn () => fn () => Http::response(array_merge(FakeAacProvider::tokenResponse(), ['id_token' => ['x']])),
]);

it('accepts an aud array that contains the client id', function () {
    FakeAacProvider::fake(['id_token' => FakeAacProvider::idToken(['aud' => ['other', FakeAacProvider::CLIENT_ID]])]);
    FakeAacProvider::startAuthorization();

    expect(oidcClient()->authenticateWith(callbackParameters()))->toBeTrue();
});

it('rejects a state mismatch', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient()->authenticateWith(['code' => 'auth-code', 'state' => 'forged']))
        ->toThrow(OpenIDConnectClientException::class, 'Unable to determine state');
});

it('surfaces errors returned by AAC on the callback', function () {
    expect(fn () => oidcClient()->authenticateWith(['error' => 'access_denied', 'error_description' => 'User cancelled']))
        ->toThrow(OpenIDConnectClientException::class, 'Error: access_denied Description: User cancelled');
});

it('ignores non-string callback parameters and restarts the authorization', function () {
    FakeAacProvider::fake();

    expect(fn () => oidcClient()->authenticateWith(['code' => ['auth-code'], 'state' => FakeAacProvider::STATE]))
        ->toThrow(HttpResponseException::class);
});

it('caches the discovery document and the JWKS', function () {
    FakeAacProvider::fake();

    FakeAacProvider::startAuthorization();
    oidcClient()->authenticateWith(callbackParameters());
    FakeAacProvider::startAuthorization();
    oidcClient()->authenticateWith(callbackParameters());

    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::discoveryUrl()))->toHaveCount(1)
        ->and(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('does not cache when cache_ttl is 0', function () {
    FakeAacProvider::fake();

    oidcClient(0)->authorizationRedirect();
    oidcClient(0)->authorizationRedirect();

    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::discoveryUrl()))->toHaveCount(2);
});

it('refreshes a cached JWKS once when AAC rotated its signing key', function () {
    FakeAacProvider::fake();
    Cache::put('spid-laravel-trentino:oidc:'.sha1(FakeAacProvider::ISSUER.'/jwk'), json_encode(['keys' => []]), 3600);
    FakeAacProvider::startAuthorization();

    expect(oidcClient()->authenticateWith(callbackParameters()))->toBeTrue()
        ->and(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('does not retry signature verification when nothing was cached', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(['keys' => []]),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(FakeAacProvider::tokenResponse()),
    ]);
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient(0)->authenticateWith(callbackParameters()))->toThrow(OpenIDConnectClientException::class);
    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('reports AAC metadata errors as OpenIDConnectClientException and does not cache them', function () {
    Http::fake([FakeAacProvider::discoveryUrl() => Http::sequence()
        ->push('<html>maintenance</html>', 503, ['Content-Type' => 'text/html'])
        ->push(FakeAacProvider::discovery())]);

    expect(fn () => oidcClient()->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'AAC returned HTTP 503 for '.FakeAacProvider::discoveryUrl());
    expect(oidcClient()->authorizationRedirect())->toBeInstanceOf(RedirectResponse::class);
});

it('reports connection failures as OpenIDConnectClientException', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => oidcClient()->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'Unable to reach AAC: Connection refused');
});

it('sends JSON bodies as JSON and exposes the response content type', function () {
    Http::fake(['https://aac.test/register' => Http::response(['client_id' => 'x'], 201)]);
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function post(string $url, string $body): string
        {
            return $this->fetchURL($url, $body, ['Accept: application/json']);
        }
    };

    expect($client->post('https://aac.test/register', '{"a":1}'))->toBe('{"client_id":"x"}')
        ->and($client->getResponseCode())->toBe(201)
        ->and($client->getResponseContentType())->toBe('application/json');

    Http::assertSent(fn ($request) => $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Accept', 'application/json'));
});

it('never caches token requests or authenticated requests', function (?string $body, array $headers) {
    Http::fake(['https://aac.test/endpoint' => Http::sequence()->push(['n' => 1])->push(['n' => 2])]);
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        /** @param array<int, string> $headers */
        public function fetch(?string $body, array $headers): string
        {
            return $this->fetchURL('https://aac.test/endpoint', $body, $headers);
        }
    };

    expect($client->fetch($body, $headers))->toBe('{"n":1}')
        ->and($client->fetch($body, $headers))->toBe('{"n":2}');
})->with([
    'public-client token POST without headers' => ['grant_type=refresh_token', []],
    'userinfo GET with bearer token' => [null, ['Authorization: Bearer x']],
]);

it('fails when a redirect exception carries a non-redirect response', function () {
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function authenticateWith(array $parameters): bool
        {
            throw new HttpResponseException(new Response('not a redirect'));
        }
    };

    expect(fn () => $client->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'The authorization request did not produce a redirect.');
});

it('never starts or commits a native PHP session', function () {
    $client = oidcClient();

    (fn () => $this->startSession())->call($client);
    (fn () => $this->commitSession())->call($client);

    expect(session_status())->toBe(PHP_SESSION_NONE);
});
