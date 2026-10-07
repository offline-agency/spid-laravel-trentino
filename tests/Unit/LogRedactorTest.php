<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;

mutates(LogRedactor::class);

it('hashes values with the application key', function () {
    $hash = LogRedactor::hash('TINIT-RSSMRA80A01H501U');

    expect($hash)->toMatch('/^[0-9a-f]{16}$/')
        ->and($hash)->toBe(LogRedactor::hash('TINIT-RSSMRA80A01H501U'))
        ->and($hash)->not->toContain('RSSMRA');

    config()->set('app.key', 'another-key');
    expect(LogRedactor::hash('TINIT-RSSMRA80A01H501U'))->not->toBe($hash);
});

it('keeps empty values empty and tolerates a missing app key', function () {
    expect(LogRedactor::hash(''))->toBe('');

    config()->set('app.key', null);
    expect(LogRedactor::hash('x'))->toMatch('/^[0-9a-f]{16}$/');
});

it('redacts a SPID user for log context', function () {
    $user = SpidTrentinoUser::fromArray(['sub' => 'subject-123', 'enti-codicefiscale' => ['fiscalCode' => 'TINIT-RSSMRA80A01H501U']]);

    expect(LogRedactor::user($user))->toBe([
        'sub' => LogRedactor::hash('subject-123'),
        'fiscal_code' => LogRedactor::hash('RSSMRA80A01H501U'),
    ]);
});
