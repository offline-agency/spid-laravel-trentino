<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use OfflineAgency\SpidLaravelTrentino\Support\TokenResponse;

mutates(TokenResponse::class);

it('normalizes object and array token responses alike', function (mixed $raw) {
    $tokens = TokenResponse::from($raw);

    expect($tokens->accessToken)->toBe('access')
        ->and($tokens->refreshToken)->toBe('refresh')
        ->and($tokens->idToken)->toBe('id')
        ->and($tokens->expiresIn)->toBe(3600)
        ->and($tokens->error)->toBeNull();
})->with([
    'stdClass (what jumbojett returns)' => [(object) ['access_token' => 'access', 'refresh_token' => 'refresh', 'id_token' => 'id', 'expires_in' => 3600]],
    'array' => [['access_token' => 'access', 'refresh_token' => 'refresh', 'id_token' => 'id', 'expires_in' => '3600']],
]);

it('treats missing, empty and malformed fields as absent', function (mixed $raw) {
    $tokens = TokenResponse::from($raw);

    expect($tokens->accessToken)->toBeNull()
        ->and($tokens->refreshToken)->toBeNull()
        ->and($tokens->expiresIn)->toBeNull()
        ->and($tokens->expiresAt())->toBeNull();
})->with([
    'null' => [null],
    'string' => ['garbage'],
    'empty values' => [['access_token' => '', 'refresh_token' => 42, 'expires_in' => 'soon']],
]);

it('exposes the provider error', function () {
    $tokens = TokenResponse::from((object) ['error' => 'invalid_grant', 'error_description' => 'expired']);

    expect($tokens->error)->toBe('invalid_grant')->and($tokens->accessToken)->toBeNull();
});

it('computes the expiry from expires_in', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));

    expect(TokenResponse::from(['expires_in' => 60])->expiresAt()?->toIso8601String())
        ->toBe('2026-01-01T12:01:00+00:00');
});
