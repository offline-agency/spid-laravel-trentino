<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Support\ClaimPath;

mutates(ClaimPath::class);

$sources = [
    'id_token' => ['acr' => 'https://www.spid.gov.it/SpidL3', 'empty' => '', 'nested' => ['deep' => ['value' => 'x']]],
    'userinfo' => ['enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL2'], 'enti-issuersource' => ['issuerSource' => 'https://idp.test'], 'number' => 2],
];

it('reads id_token and userinfo paths', function (string $path, mixed $expected) use ($sources) {
    expect(ClaimPath::first([$path], $sources))->toBe($expected);
})->with([
    ['id_token:acr', 'https://www.spid.gov.it/SpidL3'],
    ['userinfo:enti-acr.acr', 'https://www.spid.gov.it/SpidL2'],
    ['userinfo:enti-issuersource.issuerSource', 'https://idp.test'],
    ['id_token:nested.deep.value', 'x'],
    ['userinfo:number', '2'],
]);

it('returns the first non-empty value', function () use ($sources) {
    expect(ClaimPath::first(['id_token:missing', 'id_token:empty', 'userinfo:enti-acr.acr', 'id_token:acr'], $sources))
        ->toBe('https://www.spid.gov.it/SpidL2')
        ->and(ClaimPath::first(['id_token:missing'], $sources))->toBeNull()
        ->and(ClaimPath::first([], $sources))->toBeNull();
});

it('accepts a single path as a string', function () use ($sources) {
    expect(ClaimPath::first('userinfo:enti-acr.acr', $sources))->toBe('https://www.spid.gov.it/SpidL2');
});

it('ignores malformed paths and non-scalar values', function (mixed $path) use ($sources) {
    expect(ClaimPath::first([$path, 'id_token:acr'], $sources))->toBe('https://www.spid.gov.it/SpidL3');
})->with([
    'no source' => 'acr',
    'unknown source' => 'session:acr',
    'empty path' => 'userinfo:',
    'array value' => 'userinfo:enti-acr',
    'not a string' => 42,
    'path through a scalar' => 'id_token:acr.deeper',
]);
