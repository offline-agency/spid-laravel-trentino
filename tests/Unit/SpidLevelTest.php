<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Support\SpidLevel;

mutates(SpidLevel::class);

it('parses short and URI forms', function (mixed $value, int $level) {
    expect(SpidLevel::parse($value))->toBe($level);
})->with([
    ['SpidL1', 1],
    ['spidl2', 2],
    [' SPIDL3 ', 3],
    ['https://www.spid.gov.it/SpidL1', 1],
    ['https://www.spid.gov.it/SpidL2', 2],
    ['HTTPS://WWW.SPID.GOV.IT/SPIDL3', 3],
    [2, 2],
    ['3', 3],
]);

it('treats unknown values as unknown', function (mixed $value) {
    expect(SpidLevel::parse($value))->toBeNull();
})->with([null, '', 'SpidL4', 'SpidL0', 'L2', 'https://example.test/SpidL2', 'https://www.spid.gov.it/SpidL2/extra', 'https://www.spid.gov.it/2', 4, 0, [['SpidL2']], 2.0]);

it('orders levels and builds the acr_values URI', function () {
    expect(SpidLevel::parse('SpidL3'))->toBeGreaterThan(SpidLevel::parse('https://www.spid.gov.it/SpidL2'))
        ->and(SpidLevel::uri(2))->toBe('https://www.spid.gov.it/SpidL2')
        ->and(SpidLevel::name(3))->toBe('SpidL3');
});

it('reads the required level from the configuration', function (mixed $configured, ?int $level) {
    expect(SpidLevel::required($configured))->toBe($level);
})->with([
    'not set' => [null, null],
    'empty' => ['', null],
    'short' => ['SpidL2', 2],
    'uri' => ['https://www.spid.gov.it/SpidL3', 3],
]);

it('rejects an invalid required_acr', function (mixed $configured) {
    SpidLevel::required($configured);
})->with(['SpidL4', 'level two', 5, true])
    ->throws(InvalidArgumentException::class, 'spid-laravel-trentino.required_acr must be SpidL1, SpidL2 or SpidL3');
