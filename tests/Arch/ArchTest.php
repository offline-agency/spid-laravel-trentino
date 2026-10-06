<?php

declare(strict_types=1);

use Illuminate\Routing\Controller;

arch('no debugging helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'print_r'])
    ->not->toBeUsed();

arch('source files declare strict types')
    ->expect('OfflineAgency\SpidLaravelTrentino')
    ->toUseStrictTypes()
    ->ignoring('OfflineAgency\SpidLaravelTrentino\Tests');

arch('controllers extend the base routing controller')
    ->expect('OfflineAgency\SpidLaravelTrentino\Http\Controllers')
    ->toExtend(Controller::class);

test('src never touches $_SESSION directly', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

    foreach ($files as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), '$_SESSION')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

arch('src depends on illuminate components, not on the framework')
    ->expect('OfflineAgency\SpidLaravelTrentino')
    ->not->toUse([
        'Illuminate\Foundation',
        'app', 'auth', 'config', 'config_path', 'database_path', 'event', 'logger',
        'now', 'redirect', 'request', 'resource_path', 'response', 'route', 'session', 'url', '__',
    ])
    ->ignoring('OfflineAgency\SpidLaravelTrentino\Tests');
