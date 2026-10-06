<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;

it('publishes the configuration under the spid-laravel-trentino-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/spid-laravel-trentino.php')
        ->and(array_values($paths)[0])->toBe(config_path('spid-laravel-trentino.php'));
});

it('publishes the views under the spid-laravel-trentino-views tag', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-views');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('resources/views')
        ->and(array_values($paths)[0])->toBe(resource_path('views/vendor/spid-laravel-trentino'));
});

it('ships secure, non-colliding defaults', function () {
    expect(config('spid-laravel-trentino.routes'))->toBe([
        'login' => '/spid/login',
        'callback' => '/spid/callback',
        'logout' => '/spid/logout',
    ])
        ->and(config('spid-laravel-trentino.register_routes'))->toBeTrue()
        ->and(config('spid-laravel-trentino.redirect_to'))->toBeNull()
        ->and(config('spid-laravel-trentino.logout_redirect_to'))->toBe('/')
        ->and(config('spid-laravel-trentino.error_redirect_to'))->toBe('/')
        ->and(config('spid-laravel-trentino.cache_ttl'))->toBe(3600);
});
