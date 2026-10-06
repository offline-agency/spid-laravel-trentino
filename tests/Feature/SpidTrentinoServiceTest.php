<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(SpidTrentino::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));
    FakeAacProvider::startAuthorization();
    useCallbackRequest(['code' => 'auth-code', 'state' => FakeAacProvider::STATE]);
});

it('stores tokens, expiry and user, dispatches the login event and returns the user', function () {
    Event::fake([SpidTrentinoLoggedIn::class]);
    FakeAacProvider::fake();

    $user = app(SpidTrentino::class)->handleCallback();

    expect($user->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and($user->getEmail())->toBe('mario.rossi@example.com')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN))->toBe(FakeAacProvider::ACCESS_TOKEN)
        ->and(Session::get(SessionKeys::REFRESH_TOKEN))->toBe(FakeAacProvider::REFRESH_TOKEN)
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00')
        ->and(Session::get(SessionKeys::USER))->toBe($user->toArray());

    Event::assertDispatched(SpidTrentinoLoggedIn::class, fn (SpidTrentinoLoggedIn $event) => $event->getUser()->getFiscalNumber() === FakeAacProvider::FISCAL_CODE);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), FakeAacProvider::ISSUER.'/userinfo')
        && $request->hasHeader('Authorization', 'Bearer '.FakeAacProvider::ACCESS_TOKEN));
});

it('handles array token responses from custom clients', function () {
    app()->bind(LaravelOpenIDConnectClient::class, fn () => new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function authenticate(): bool
        {
            return true;
        }

        public function getTokenResponse(): array
        {
            return ['access_token' => 'array-access', 'expires_in' => '120'];
        }

        public function requestUserInfo(?string $attribute = null): mixed
        {
            return (object) FakeAacProvider::userInfo();
        }
    });
    app()->forgetScopedInstances();

    app(SpidTrentino::class)->handleCallback();

    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('array-access')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T12:02:00+00:00')
        ->and(Session::has(SessionKeys::REFRESH_TOKEN))->toBeFalse();
});

it('stores no expiry when AAC sends no expires_in', function () {
    FakeAacProvider::fake(['expires_in' => null]);

    app(SpidTrentino::class)->handleCallback();

    expect(Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();
});

it('refuses a userinfo without fiscal code', function (array $userInfo) {
    FakeAacProvider::fake(userInfo: $userInfo);

    expect(fn () => app(SpidTrentino::class)->handleCallback())
        ->toThrow(OpenIDConnectClientException::class, 'AAC did not return a fiscal code for the authenticated user.');
    expect(Session::has(SessionKeys::USER))->toBeFalse();
})->with([
    'claim missing' => [['enti-codicefiscale' => null]],
    'empty fiscal code' => [['enti-codicefiscale' => ['fiscalCode' => '']]],
]);

it('refuses a userinfo that is not a JSON object', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(FakeAacProvider::tokenResponse()),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response('[]'),
    ]);

    expect(fn () => app(SpidTrentino::class)->handleCallback())->toThrow(OpenIDConnectClientException::class);
});

it('never logs tokens, credentials or personal data', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    FakeAacProvider::fake();

    $spid = app(SpidTrentino::class);
    $spid->handleCallback();
    $spid->refreshAccessToken();
    $spid->logout();

    $all = implode("\n", $logged);
    expect($logged)->not->toBeEmpty();
    foreach ([FakeAacProvider::ACCESS_TOKEN, FakeAacProvider::REFRESH_TOKEN, FakeAacProvider::CLIENT_SECRET, FakeAacProvider::FISCAL_CODE, 'RSSMRA80A01H501U', 'Mario', 'Rossi', 'mario.rossi@example.com', 'eyJ'] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('refreshes the access token with the stored refresh token', function () {
    FakeAacProvider::fake(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 600, 'id_token' => null]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');

    app(SpidTrentino::class)->refreshAccessToken();

    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('new-access')
        ->and(Session::get(SessionKeys::REFRESH_TOKEN))->toBe('new-refresh')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T12:10:00+00:00');
    Http::assertSent(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/oauth/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

it('keeps the current refresh token when AAC does not rotate it', function () {
    FakeAacProvider::fake(['access_token' => 'new-access', 'refresh_token' => null, 'id_token' => null]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');

    app(SpidTrentino::class)->refreshAccessToken();

    expect(Session::get(SessionKeys::REFRESH_TOKEN))->toBe('old-refresh');
});

it('throws when AAC refuses the refresh', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');
    Session::put(SessionKeys::ACCESS_TOKEN, 'old-access');

    expect(fn () => app(SpidTrentino::class)->refreshAccessToken())
        ->toThrow(OpenIDConnectClientException::class, 'AAC refused the token refresh: invalid_grant');
    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('old-access');
});

it('does nothing without a refresh token', function (mixed $stored) {
    Http::fake();
    Session::put(SessionKeys::REFRESH_TOKEN, $stored);

    app(SpidTrentino::class)->refreshAccessToken();

    Http::assertNothingSent();
})->with(['missing' => null, 'empty' => '']);

it('logs out, ends the session and dispatches the logout event', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'm@example.com', 'password' => 'x']);
    $this->actingAs($user);
    Session::put(SessionKeys::USER, FakeAacProvider::userInfo());
    Session::put(SessionKeys::ACCESS_TOKEN, 'access');

    app(SpidTrentino::class)->logout();

    $this->assertGuest();
    expect(Session::has(SessionKeys::USER))->toBeFalse()
        ->and(Session::has(SessionKeys::ACCESS_TOKEN))->toBeFalse();
    Event::assertDispatched(SpidTrentinoLoggedOut::class, fn (SpidTrentinoLoggedOut $event) => $event->getUser()->getFiscalNumber() === FakeAacProvider::FISCAL_CODE);
});

it('does not dispatch the logout event without a SPID user', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    Session::put(SessionKeys::USER, 'corrupted');

    app(SpidTrentino::class)->logout();

    Event::assertNotDispatched(SpidTrentinoLoggedOut::class);
});

it('requests userinfo with the access token kept in the session', function () {
    FakeAacProvider::fake();
    Session::put(SessionKeys::ACCESS_TOKEN, 'session-access');

    $userInfo = app(SpidTrentino::class)->getUserInfo();

    expect($userInfo)->toBeObject()->and($userInfo->sub)->toBe('subject-123');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer session-access'));
});

it('returns null when userinfo is not an object', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response('[]'),
    ]);

    expect(app(SpidTrentino::class)->getUserInfo())->toBeNull();
});

it('returns a SpidTrentinoUser from the facade callback', function () {
    FakeAacProvider::fake();

    expect(\SpidTrentino::handleCallback())->toBeInstanceOf(SpidTrentinoUser::class);
});
