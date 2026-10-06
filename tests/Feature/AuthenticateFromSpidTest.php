<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\NotAuthenticatable;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

mutates(SpidAuthenticatesUsers::class, SpidAuthController::class);

function loginWithSpid(array $claims = []): void
{
    app()->instance(LaravelOpenIDConnectClient::class, (new MockOpenIDConnectClient)->withUserInfo($claims));
    app()->forgetScopedInstances();
    test()->get('/spid/callback');
}

it('creates the user on first login', function () {
    loginWithSpid();

    $user = User::query()->sole();
    expect($user->fiscal_code)->toBe(MockOpenIDConnectClient::FISCAL_CODE)
        ->and($user->name)->toBe('Mario')
        ->and($user->surname)->toBe('Rossi')
        ->and($user->email)->toBe('mario.rossi@example.com')
        ->and($user->preferred_username)->toBe('mario.rossi')
        ->and($user->locale)->toBe('it')
        ->and($user->zoneinfo)->toBe('Europe/Rome')
        ->and($user->password)->toBeNull()
        ->and($user->spid_profile['enti-codicefiscale']['fiscalCode'])->toBe(MockOpenIDConnectClient::FISCAL_CODE);
    $this->assertAuthenticatedAs($user);
});

it('updates the same user on later logins', function () {
    loginWithSpid();
    auth()->logout();

    loginWithSpid(['family_name' => 'Bianchi']);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->surname)->toBe('Bianchi');
});

it('does not take over an email that belongs to another account', function () {
    $other = User::query()->forceCreate(['name' => 'Local', 'email' => 'mario.rossi@example.com', 'password' => 'secret']);

    loginWithSpid();

    $spidUser = User::query()->where('fiscal_code', MockOpenIDConnectClient::FISCAL_CODE)->sole();
    expect($spidUser->is($other))->toBeFalse()
        ->and($spidUser->email)->toBeNull()
        ->and($other->fresh()->email)->toBe('mario.rossi@example.com');
});

it('stores no email when AAC sends none', function () {
    loginWithSpid(['email' => '']);

    expect(User::query()->sole()->email)->toBeNull();
});

it('fails loudly when the configured user model is not an authenticatable Eloquent model', function (string $model) {
    config()->set('auth.providers.users.model', $model);
    $this->withoutExceptionHandling();

    expect(fn () => loginWithSpid())->toThrow(LogicException::class);
})->with([stdClass::class, NotAuthenticatable::class]);

it('publishes the migration under spid-laravel-trentino-migrations', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('database/migrations')
        ->and(array_values($paths)[0])->toBe(database_path('migrations'));
});

it('rolls the migration back', function () {
    $migration = require __DIR__.'/../../database/migrations/add_spid_trentino_columns_to_users_table.php';

    $migration->down();

    foreach (['fiscal_code', 'surname', 'preferred_username', 'locale', 'zoneinfo', 'spid_profile'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeFalse();
    }
});
