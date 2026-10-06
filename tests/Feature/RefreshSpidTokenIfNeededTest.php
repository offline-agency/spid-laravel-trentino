<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(RefreshSpidTokenIfNeeded::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));
    Route::middleware(['web', 'spid.refresh'])->get('/refreshing', fn () => 'ok');
    Route::middleware(['web', 'spid.refresh', 'spid.valid'])->get('/guarded', fn () => 'ok');
});

/** @return array<string, mixed> */
function tokenSession(mixed $expiresAt): array
{
    return [
        SessionKeys::USER => FakeAacProvider::userInfo(),
        SessionKeys::ACCESS_TOKEN => 'old-access',
        SessionKeys::REFRESH_TOKEN => 'old-refresh',
        SessionKeys::ACCESS_TOKEN_EXPIRES_AT => $expiresAt,
    ];
}

it('does nothing without a refresh token or far from expiry', function (array $session) {
    Http::fake();

    $this->withSession($session)->get('/refreshing')->assertOk();

    Http::assertNothingSent();
})->with([
    'no refresh token' => fn () => [SessionKeys::ACCESS_TOKEN_EXPIRES_AT => '2026-01-01T12:00:30+00:00'],
    'far from expiry' => fn () => tokenSession('2026-01-01T13:00:00+00:00'),
    'no expiry known' => fn () => array_diff_key(tokenSession(null), [SessionKeys::ACCESS_TOKEN_EXPIRES_AT => true]),
]);

it('refreshes the token within a minute of expiry', function (mixed $expiresAt) {
    FakeAacProvider::fake(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => null]);

    $this->withSession(tokenSession($expiresAt))->get('/refreshing')->assertOk();

    expect(session(SessionKeys::ACCESS_TOKEN))->toBe('new-access')
        ->and(session(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00');
})->with([
    'ISO string' => '2026-01-01T12:00:30+00:00',
    'legacy Carbon' => fn () => CarbonImmutable::parse('2026-01-01T12:00:30+00:00'),
    'already expired' => '2026-01-01T11:00:00+00:00',
]);

it('forgets the tokens when the refresh fails so spid.valid ends the session', function (string $failure) {
    match ($failure) {
        'invalid_grant' => Http::fake([
            FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
            FakeAacProvider::ISSUER.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]),
        'unreachable' => Http::fake(fn () => throw new ConnectionException('timeout')),
    };

    $this->withSession(tokenSession('2026-01-01T12:00:30+00:00'))->get('/refreshing')->assertOk();

    expect(session()->has(SessionKeys::ACCESS_TOKEN))->toBeFalse()
        ->and(session()->has(SessionKeys::REFRESH_TOKEN))->toBeFalse()
        ->and(session()->has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();

    $this->get('/guarded')->assertRedirect('/spid/login');
})->with(['invalid_grant', 'unreachable']);
