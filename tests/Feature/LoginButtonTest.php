<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the SPID login button linking to the login route', function () {
    $html = Blade::render('<x-spid-laravel-trentino::login-button />');

    expect($html)->toContain('href="'.route('spid.login').'"')
        ->toContain('Entra con SPID')
        ->toContain('class="btn btn-light-primary align-self-center w-100"');
});

it('accepts a custom label and extra classes', function () {
    $html = Blade::render('<x-spid-laravel-trentino::login-button label="Accedi" class="mt-4" />');

    expect($html)->toContain('Accedi')->toContain('w-100 mt-4"');
});
