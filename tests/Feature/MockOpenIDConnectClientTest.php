<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient;

beforeEach(function () {
    app()->instance(LaravelOpenIDConnectClient::class, (new MockOpenIDConnectClient)->withUserInfo(['given_name' => 'Giulia']));
    app()->forgetScopedInstances();
});

it('lets apps fake the whole SPID login', function () {
    $this->get('/spid/login')->assertRedirect('https://aac.mock.invalid/authorize');

    $this->get('/spid/callback')->assertRedirect('/');

    $this->assertAuthenticated();
    expect(session(SessionKeys::USER)['enti-codicefiscale']['fiscalCode'])->toBe(MockOpenIDConnectClient::FISCAL_CODE)
        ->and(session(SessionKeys::USER)['given_name'])->toBe('Giulia')
        ->and(session(SessionKeys::ACCESS_TOKEN))->toBe(MockOpenIDConnectClient::ACCESS_TOKEN)
        ->and(session(SessionKeys::REFRESH_TOKEN))->toBe(MockOpenIDConnectClient::REFRESH_TOKEN);
});

it('fakes refresh and userinfo', function () {
    $mock = new MockOpenIDConnectClient;

    expect($mock->refreshToken('r')->access_token)->toBe('mock-refreshed-access-token')
        ->and($mock->getIdToken())->toBe(MockOpenIDConnectClient::ID_TOKEN)
        ->and($mock->requestUserInfo('family_name'))->toBe('Rossi')
        ->and($mock->requestUserInfo('missing'))->toBeNull();

    session()->put(SessionKeys::REFRESH_TOKEN, 'r');
    app(SpidTrentino::class)->refreshAccessToken();
    expect(session(SessionKeys::ACCESS_TOKEN))->toBe('mock-refreshed-access-token');
});
