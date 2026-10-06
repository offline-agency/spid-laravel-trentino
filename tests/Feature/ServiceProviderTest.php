<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoFacade;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;

it('merges the package configuration', function () {
    expect(config('spid-laravel-trentino.provider_url'))->toBe('https://aac.test')
        ->and(config('spid-laravel-trentino.scopes'))->toBeString();
});

it('resolves the facade and its alias to the SpidTrentino service', function () {
    expect(SpidTrentinoFacade::getFacadeRoot())->toBeInstanceOf(SpidTrentino::class)
        ->and(\SpidTrentino::getFacadeRoot())->toBe(SpidTrentinoFacade::getFacadeRoot());
});

it('keeps one SpidTrentino instance per request', function () {
    $first = app(SpidTrentino::class);

    expect(app(SpidTrentino::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(SpidTrentino::class))->not->toBe($first);
});

it('builds the OIDC client from configuration', function () {
    $client = app(LaravelOpenIDConnectClient::class);

    expect($client->getProviderURL())->toBe('https://aac.test')
        ->and($client->getClientID())->toBe('test-client')
        ->and($client->getClientSecret())->toBe('test-secret')
        ->and($client->getRedirectURL())->toBe('https://app.test/spid/callback')
        ->and($client->getScopes())->toBe(['profile.codicefiscale.me', 'email', 'offline_access'])
        ->and($client->getCodeChallengeMethod())->toBe('S256')
        ->and(app(LaravelOpenIDConnectClient::class))->not->toBe($client);
});

it('defaults the redirect URI to the callback route and treats an empty secret as a public client', function () {
    config()->set('spid-laravel-trentino.redirect_uri', null);
    config()->set('spid-laravel-trentino.client_secret', '');

    $client = app(LaravelOpenIDConnectClient::class);

    expect($client->getRedirectURL())->toBe(url('/spid/callback'))
        ->and($client->getClientSecret())->toBeNull();
});

it('falls back to the default cache TTL when cache_ttl is not numeric', function () {
    config()->set('spid-laravel-trentino.cache_ttl', 'forever');

    expect((fn () => $this->cacheTtl)->call(app(LaravelOpenIDConnectClient::class)))->toBe(3600);
});

it('registers the spid.valid and spid.refresh middleware aliases', function () {
    expect(app('router')->getMiddleware())
        ->toMatchArray([
            'spid.valid' => EnsureValidSpidToken::class,
            'spid.refresh' => RefreshSpidTokenIfNeeded::class,
        ]);
});

it('registers login, callback and logout routes', function () {
    expect(route('spid.login', absolute: false))->toBe('/spid/login')
        ->and(route('spid.callback', absolute: false))->toBe('/spid/callback')
        ->and(route('spid.logout', absolute: false))->toBe('/spid/logout')
        ->and(Route::getRoutes()->getByName('spid.logout')->methods())->toBe(['POST']);
});

it('skips route registration when register_routes is false', function (bool $register) {
    config()->set('spid-laravel-trentino.register_routes', $register);
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    (new SpidTrentinoServiceProvider(app()))->boot($router);
    $router->getRoutes()->refreshNameLookups();

    expect(Route::has('spid.login'))->toBe($register);
})->with(['disabled' => false, 'enabled' => true]);
