<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Exceptions\AuthenticationRejected;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

mutates(SpidTrentino::class, AuthenticationRejected::class, SpidAuthenticatesUsers::class, SpidTrentinoServiceProvider::class);

const LEVEL_TOO_LOW = 'Your SPID login does not meet the security level required by this service.';
const SOURCE_NOT_ALLOWED = 'Your identity provider is not accepted by this service.';

/**
 * Runs a SPID callback against a fake AAC whose ID token carries $idTokenAcr
 * (null: no acr claim) and whose userinfo carries the given overrides.
 */
function levelCallback(?string $idTokenAcr, array $userInfo = []): TestResponse
{
    FakeAacProvider::fake(['id_token' => FakeAacProvider::idToken(['acr' => $idTokenAcr])], $userInfo);
    FakeAacProvider::startAuthorization();

    return test()->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE);
}

beforeEach(fn () => config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed'));

it('changes nothing when required_acr is null', function () {
    expect(config('spid-laravel-trentino.required_acr'))->toBeNull()
        ->and(config('spid-laravel-trentino.allowed_issuer_sources'))->toBeNull()
        ->and(config('spid-laravel-trentino.claims'))->toBe([
            'acr' => ['id_token:acr', 'userinfo:enti-acr.acr'],
            'issuer_source' => ['userinfo:enti-issuersource.issuerSource'],
        ]);

    levelCallback(null, ['enti-acr' => null, 'enti-issuersource' => null])->assertRedirect('/');
    $this->assertAuthenticated();

    FakeAacProvider::fake();
    expect($this->get('/spid/login')->headers->get('Location'))->not->toContain('acr_values');
});

it('sends acr_values when a level is required', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');
    FakeAacProvider::fake();

    $location = (string) $this->get('/spid/login')->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['acr_values'])->toBe('https://www.spid.gov.it/SpidL2');
});

it('accepts a login at the required level or higher', function (string $acr) {
    config()->set('spid-laravel-trentino.required_acr', 'https://www.spid.gov.it/SpidL2');

    levelCallback($acr)->assertRedirect('/');

    $this->assertAuthenticated();
})->with(['same level' => 'https://www.spid.gov.it/SpidL2', 'higher level' => 'https://www.spid.gov.it/SpidL3']);

it('rejects a lower level with a distinct message', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');

    levelCallback('https://www.spid.gov.it/SpidL1')
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, LEVEL_TOO_LOW);

    $this->assertGuest();
});

it('prefers the ID token acr over enti-acr', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');

    levelCallback('https://www.spid.gov.it/SpidL1', ['enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL3']])
        ->assertSessionHas(SessionKeys::ERROR, LEVEL_TOO_LOW);
});

it('falls back to enti-acr', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');

    levelCallback(null, ['enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL3']])->assertRedirect('/');

    $this->assertAuthenticated();
});

it('rejects a login whose level cannot be determined', function (?string $idTokenAcr, mixed $entiAcr) {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL1');

    levelCallback($idTokenAcr, ['enti-acr' => $entiAcr])
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, LEVEL_TOO_LOW);

    $this->assertGuest();
})->with([
    'no claims' => [null, null],
    'unknown value' => ['https://www.spid.gov.it/SpidL9', null],
    'empty enti-acr' => [null, ['acr' => '']],
]);

it('accepts any issuer source by default', function () {
    levelCallback(null, ['enti-issuersource' => ['issuerSource' => 'https://any-idp.test']])->assertRedirect('/');

    $this->assertAuthenticated();
});

it('accepts an issuer source in the allowed list', function (mixed $allowed) {
    config()->set('spid-laravel-trentino.allowed_issuer_sources', $allowed);

    levelCallback(null)->assertRedirect('/');

    $this->assertAuthenticated();
})->with([
    'env string' => 'https://other.test, https://idp.test',
    'array' => [['https://idp.test']],
    'empty string means any' => '',
]);

it('rejects an issuer source outside the allowed list', function (mixed $issuerSource) {
    config()->set('spid-laravel-trentino.allowed_issuer_sources', 'https://other.test');

    levelCallback(null, ['enti-issuersource' => $issuerSource])
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, SOURCE_NOT_ALLOWED);

    $this->assertGuest();
})->with(['other source' => [['issuerSource' => 'https://idp.test']], 'missing source' => null]);

it('uses overridden claim paths', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');
    config()->set('spid-laravel-trentino.claims.acr', ['userinfo:spid_level']);
    config()->set('spid-laravel-trentino.allowed_issuer_sources', ['https://idp.test']);
    config()->set('spid-laravel-trentino.claims.issuer_source', ['id_token:idp']);

    FakeAacProvider::fake(
        ['id_token' => FakeAacProvider::idToken(['acr' => 'https://www.spid.gov.it/SpidL1', 'idp' => 'https://idp.test'])],
        ['spid_level' => 'SpidL2', 'enti-issuersource' => ['issuerSource' => 'https://other.test']],
    );
    FakeAacProvider::startAuthorization();

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE)->assertRedirect('/');
    $this->assertAuthenticated();
});

it('logs authentication_rejected with no tokens', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');
    config()->set('spid-laravel-trentino.allowed_issuer_sources', 'https://idp.test');

    levelCallback('https://www.spid.gov.it/SpidL1');

    $row = SpidTransactionLog::query()->where('event_type', 'authentication_rejected')->sole();
    expect($row->payloadData())->toBe([
        'reason' => 'acr',
        'required' => 'SpidL2',
        'actual' => 'https://www.spid.gov.it/SpidL1',
        'issuer_source' => 'https://idp.test',
    ])
        ->and($row->sub)->toBe('subject-123')
        ->and($row->payload)->not->toContain(FakeAacProvider::ACCESS_TOKEN)
        ->and($row->transaction_id)->toBe(SpidTransactionLog::query()->where('event_type', 'userinfo_response')->value('transaction_id'));
});

it('logs the issuer source rejection reason', function () {
    config()->set('spid-laravel-trentino.allowed_issuer_sources', 'https://other.test');

    levelCallback(null);

    expect(SpidTransactionLog::query()->where('event_type', 'authentication_rejected')->sole()->payloadData())->toBe([
        'reason' => 'issuer_source',
        'required' => 'https://other.test',
        'actual' => 'https://idp.test',
        'issuer_source' => 'https://idp.test',
    ]);
});

it('stores nothing in the session for a rejected login', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL3');
    Event::fake([SpidTrentinoLoggedIn::class]);

    levelCallback('https://www.spid.gov.it/SpidL2');

    expect(Session::has(SessionKeys::USER))->toBeFalse()
        ->and(Session::has(SessionKeys::ACCESS_TOKEN))->toBeFalse()
        ->and(Session::has(SessionKeys::REFRESH_TOKEN))->toBeFalse();
    Event::assertNotDispatched(SpidTrentinoLoggedIn::class);
});

it('logs the rejection without personal data', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');
    $logged = [];
    Event::listen(MessageLogged::class, function ($message) use (&$logged): void {
        $logged[] = $message->level.' '.$message->message.' '.json_encode($message->context);
    });

    levelCallback('https://www.spid.gov.it/SpidL1');

    $log = implode("\n", $logged);
    expect($log)->toContain('warning [SPID] Login rejected {"reason":"acr","message":"The SPID level https:\/\/www.spid.gov.it\/SpidL1 is below the required SpidL2."}')
        ->and($log)->not->toContain(FakeAacProvider::FISCAL_CODE);
});

it('rejects an invalid required_acr at boot', function () {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL4');
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    (new SpidTrentinoServiceProvider(app()))->boot($router);
})->throws(InvalidArgumentException::class, 'spid-laravel-trentino.required_acr must be SpidL1, SpidL2 or SpidL3');

it('does not read a bare number as a SPID level', function (mixed $acr) {
    config()->set('spid-laravel-trentino.required_acr', 'SpidL2');

    levelCallback(null, ['enti-acr' => ['acr' => $acr]])
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, LEVEL_TOO_LOW);

    $this->assertGuest();
})->with(['string' => '2', 'int' => 3]);
