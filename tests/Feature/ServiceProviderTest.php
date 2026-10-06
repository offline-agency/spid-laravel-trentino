<?php

declare(strict_types=1);

it('merges the package configuration', function () {
    expect(config('spid-laravel-trentino.provider_url'))->toBe('https://aac.test')
        ->and(config('spid-laravel-trentino.scopes'))->toBeString();
});
