<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Support\RouteThrottle;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(RouteThrottle::class, SpidTrentinoServiceProvider::class);

function rebootWithThrottle(mixed $throttle): void
{
    config()->set('spid-laravel-trentino.throttle', $throttle);
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    (new SpidTrentinoServiceProvider(app()))->boot($router);
    $router->getRoutes()->refreshNameLookups();
}

/**
 * @return list<int>
 */
function statuses(string $method, string $uri, int $times, string $ip = '10.0.0.1'): array
{
    $statuses = [];

    for ($i = 0; $i < $times; $i++) {
        $statuses[] = test()->withServerVariables(['REMOTE_ADDR' => $ip])->call($method, $uri)->getStatusCode();
    }

    return $statuses;
}

beforeEach(fn () => FakeAacProvider::fake());

it('allows 20 login requests per minute per IP and answers 429 after', function () {
    $statuses = statuses('GET', '/spid/login', 21);

    expect(array_slice($statuses, 0, 20))->each->toBe(302)
        ->and($statuses[20])->toBe(429);
});

it('throttles the callback route too, in its own bucket', function () {
    statuses('GET', '/spid/login', 20);

    $statuses = statuses('GET', '/spid/callback', 21);

    expect(array_slice($statuses, 0, 20))->each->toBe(302)
        ->and($statuses[20])->toBe(429);
});

it('keys the limit by client IP', function () {
    statuses('GET', '/spid/login', 21, '10.0.0.1');

    expect(statuses('GET', '/spid/login', 1, '10.0.0.2'))->toBe([302]);
});

it('does not throttle logout', function () {
    expect(statuses('POST', '/spid/logout', 25))->each->toBe(302);
});

it('does not throttle when throttle is disabled', function (mixed $value) {
    rebootWithThrottle($value);

    expect(statuses('GET', '/spid/login', 25))->each->toBe(302);
})->with(['null' => null, 'false' => false, 'empty string' => '']);

it('parses custom limits', function () {
    rebootWithThrottle('3,2');

    expect(statuses('GET', '/spid/login', 4))->toBe([302, 302, 302, 429]);

    $this->travel(61)->seconds();
    expect(statuses('GET', '/spid/login', 1))->toBe([429]);

    $this->travel(60)->seconds();
    expect(statuses('GET', '/spid/login', 1))->toBe([302]);
});

it('reads a bare number as requests per minute', function (mixed $value) {
    rebootWithThrottle($value);

    expect(statuses('GET', '/spid/login', 3))->toBe([302, 302, 429]);
})->with(['string' => '2', 'int' => 2, 'spaces' => ' 2 , 1 ']);

it('rejects an invalid throttle value at boot', function (mixed $value) {
    rebootWithThrottle($value);
})->with([
    'text' => 'many',
    'zero requests' => '0,1',
    'zero minutes' => '5,0',
    'negative' => '-1',
    'three parts' => '1,2,3',
    'decimal' => '1.5',
    'array' => [[20, 1]],
])->throws(InvalidArgumentException::class, 'spid-laravel-trentino.throttle must be "max" or "max,decayMinutes"');

it('writes no transaction log row for a throttled request', function () {
    statuses('GET', '/spid/login', 20);
    $written = SpidTransactionLog::query()->count();

    statuses('GET', '/spid/login', 3);

    expect($written)->toBe(20)
        ->and(SpidTransactionLog::query()->count())->toBe(20);
});

it('defaults to 20 requests per minute', function () {
    expect(config('spid-laravel-trentino.throttle'))->toBe('20,1')
        ->and(RouteThrottle::parse('20,1'))->toBe([20, 1]);
});

it('keeps the middleware on the routes when throttle is disabled, so cached routes stay valid', function () {
    rebootWithThrottle(null);

    expect(app('router')->getRoutes()->getByName('spid.login')->gatherMiddleware())
        ->toBe(['web', ThrottleRequests::using(RouteThrottle::LIMITER)]);
});

it('keeps routes with the limiter working when throttle is disabled from boot', function () {
    // A fresh application booted with throttle = null has never registered the limiter.
    app()->instance(CacheRateLimiter::class, new CacheRateLimiter(app('cache')->driver()));
    RateLimiter::clearResolvedInstances();
    rebootWithThrottle(null);
    Route::get('/own-login', fn () => 'ok')->middleware(ThrottleRequests::using(RouteThrottle::LIMITER));

    expect(statuses('GET', '/own-login', 25))->each->toBe(200);
});
