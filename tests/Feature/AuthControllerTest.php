<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
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

it('logs the user in on callback and redirects to the intended page', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE)->assertRedirect('/');

    $this->assertAuthenticated();
});

it('accepts the session created by the callback on spid.valid routes', function () {
    Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->get('/reserved', fn () => 'reserved');
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE);

    $this->get('/reserved')->assertOk()->assertSee('reserved');
});

it('redirects to the error page with a flash message when the callback fails', function (array $query, array $idTokenClaims) {
    FakeAacProvider::fake($idTokenClaims === [] ? [] : ['id_token' => FakeAacProvider::idToken($idTokenClaims)]);
    FakeAacProvider::startAuthorization();
    config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed');

    $this->get('/spid/callback?'.http_build_query($query))
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'user cancelled on AAC' => [['error' => 'access_denied'], []],
    'forged state' => [['code' => 'auth-code', 'state' => 'forged'], []],
    'ID token for another client' => [['code' => 'auth-code', 'state' => FakeAacProvider::STATE], ['aud' => 'another-client']],
]);

it('logs out through the service, dispatching the logout event', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'm@example.com', 'password' => 'x']);
    config()->set('spid-laravel-trentino.logout_redirect_to', '/goodbye');

    $this->actingAs($user)
        ->withSession([SessionKeys::USER => FakeAacProvider::userInfo()])
        ->post('/spid/logout')
        ->assertRedirect('/goodbye');

    $this->assertGuest();
    Event::assertDispatched(SpidTrentinoLoggedOut::class);
});
