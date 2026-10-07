<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Support\FiscalCode;

mutates(FiscalCode::class);

it('trims, uppercases and strips TINIT-', function (string $raw) {
    expect(FiscalCode::normalize($raw))->toBe('RSSMRA80A01H501U');
})->with([
    'AAC form' => 'TINIT-RSSMRA80A01H501U',
    'lowercase prefix and code' => 'tinit-rssmra80a01h501u',
    'surrounding whitespace' => "  TINIT-RSSMRA80A01H501U \n",
    'no prefix' => 'RSSMRA80A01H501U',
    'lowercase without prefix' => 'rssmra80a01h501u',
]);

it('is idempotent', function () {
    $once = FiscalCode::normalize(' tinit-rssmra80a01h501u ');

    expect(FiscalCode::normalize($once))->toBe($once);
});

it('keeps codes without prefix and strips the prefix only once, at the start', function () {
    expect(FiscalCode::normalize('12345678901'))->toBe('12345678901')
        ->and(FiscalCode::normalize('TINIT-TINIT-X'))->toBe('TINIT-X')
        ->and(FiscalCode::normalize('XTINIT-Y'))->toBe('XTINIT-Y');
});

it('returns an empty string for blank input', function (string $raw) {
    expect(FiscalCode::normalize($raw))->toBe('');
})->with(['', '   ', 'TINIT-', ' tinit- ']);
