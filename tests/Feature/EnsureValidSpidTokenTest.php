<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;

beforeEach(function () {
    Route::middleware(['web', EnsureValidSpidToken::class])->get('/protected', fn () => 'ok');
    $this->freezeTime();
});

function validSpidSession(array $overrides = []): array
{
    return array_merge([
        SessionKeys::USER => ['sub' => 'subject-123', 'enti-codicefiscale' => ['fiscalCode' => 'TINIT-RSSMRA80A01H501U']],
        SessionKeys::ACCESS_TOKEN => 'access-token',
        SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->addHour()->toIso8601String(),
    ], $overrides);
}

it('lets the request through when the session written at login is valid', function () {
    $this->withSession(validSpidSession())->get('/protected')->assertOk()->assertSee('ok');
});

it('lets the request through when no expiry is known', function () {
    $session = validSpidSession();
    unset($session[SessionKeys::ACCESS_TOKEN_EXPIRES_AT]);

    $this->withSession($session)->get('/protected')->assertOk();
});

it('redirects to the SPID login and ends the session when it is invalid', function (array $session) {
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'mario@example.com', 'password' => 'secret']);

    $this->actingAs($user)->withSession($session)->get('/protected')->assertRedirect('/spid/login');

    $this->assertGuest();
    expect(session()->has(SessionKeys::USER))->toBeFalse();
})->with([
    'no SPID user' => fn () => array_diff_key(validSpidSession(), [SessionKeys::USER => true]),
    'no access token' => fn () => array_diff_key(validSpidSession(), [SessionKeys::ACCESS_TOKEN => true]),
    'expired (ISO string)' => fn () => validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->subMinute()->toIso8601String()]),
    'expired (legacy Carbon instance)' => fn () => validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->subMinute()]),
    'unparseable expiry' => fn () => validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => 'not-a-date']),
]);

it('answers 419 JSON to API clients when the session is invalid', function () {
    $this->getJson('/protected')
        ->assertStatus(419)
        ->assertExactJson(['message' => 'SPID session missing or expired.']);
});
