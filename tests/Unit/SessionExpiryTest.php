<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;

mutates(SessionExpiry::class);

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00')));

it('stores the expiry as an ISO-8601 string and forgets it when null', function () {
    SessionExpiry::put(CarbonImmutable::now()->addHour());
    expect(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00');

    SessionExpiry::put(null);
    expect(Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();
});

it('reads every format older releases stored', function (mixed $raw) {
    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, $raw);

    expect(SessionExpiry::get()?->toIso8601String())->toBe('2026-01-01T13:00:00+00:00');
})->with([
    'ISO string' => '2026-01-01T13:00:00+00:00',
    'Carbon' => fn () => now()->addHour(),
    'DateTimeImmutable' => fn () => new DateTimeImmutable('2026-01-01T13:00:00+00:00'),
    'unix timestamp' => 1767272400,
]);

it('returns null for missing or unparseable values', function (mixed $raw) {
    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, $raw);

    expect(SessionExpiry::get())->toBeNull();
})->with(['empty string' => '', 'garbage' => 'not-a-date', 'array' => [[1]], 'null' => null]);

it('knows when the token has expired', function () {
    expect(SessionExpiry::hasExpired())->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSecond());
    expect(SessionExpiry::hasExpired())->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now());
    expect(SessionExpiry::hasExpired())->toBeTrue();

    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, 'not-a-date');
    expect(SessionExpiry::hasExpired())->toBeTrue();
});

it('knows when the token expires within a window', function () {
    expect(SessionExpiry::expiresWithin(60))->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSeconds(61));
    expect(SessionExpiry::expiresWithin(60))->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSeconds(60));
    expect(SessionExpiry::expiresWithin(60))->toBeTrue();
});
