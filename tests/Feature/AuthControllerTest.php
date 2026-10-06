<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

it('redirects the login route to the AAC authorization endpoint', function () {
    FakeAacProvider::fake();

    $response = $this->get('/spid/login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(FakeAacProvider::ISSUER.'/oauth/authorize?')
        ->and(session()->has(SessionKeys::OIDC_PREFIX.'openid_connect_state'))->toBeTrue();
});

it('sends the user to the error page when AAC cannot be reached at login', function () {
    Http::fake([FakeAacProvider::discoveryUrl() => Http::response('down', 503)]);
    config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed');

    $this->get('/spid/login')
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
});
