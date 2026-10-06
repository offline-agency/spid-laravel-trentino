<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\AdminUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

function postLoginTarget(): string
{
    return (new class
    {
        use SpidAuthenticatesUsers;

        public function target(): string
        {
            return $this->redirectTo();
        }
    })->target();
}

it('uses the configured redirect_to path', function () {
    config()->set('spid-laravel-trentino.redirect_to', '/area-riservata');

    expect(postLoginTarget())->toBe('/area-riservata');
});

it('sends admins to the admin dashboard when redirect_to is not set', function () {
    config()->set('spid-laravel-trentino.redirect_to', null);
    $this->actingAs(new AdminUser(['name' => 'Admin']));

    expect(postLoginTarget())->toBe('/admin/dashboard');
});

it('falls back to the home page', function (mixed $configured) {
    config()->set('spid-laravel-trentino.redirect_to', $configured);
    $this->actingAs(new User(['name' => 'Mario']));

    expect(postLoginTarget())->toBe('/');
})->with(['null' => null, 'empty string' => '']);
