<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(SpidTrentinoUser::class);

it('maps every AAC claim', function () {
    $user = SpidTrentinoUser::fromArray(FakeAacProvider::userInfo());

    expect($user->getSub())->toBe('subject-123')
        ->and($user->getName())->toBe('Mario')
        ->and($user->getGivenName())->toBe('Mario')
        ->and($user->getSurname())->toBe('Rossi')
        ->and($user->getFamilyName())->toBe('Rossi')
        ->and($user->getEmail())->toBe('mario.rossi@example.com')
        ->and($user->getPreferredUsername())->toBe('mario.rossi')
        ->and($user->getLocale())->toBe('it')
        ->and($user->getZoneinfo())->toBe('Europe/Rome')
        ->and($user->getRealm())->toBe('test-realm')
        ->and($user->getId())->toBe('user-123')
        ->and($user->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and($user->getEntiCodiceFiscale())->toBe(['fiscalCode' => FakeAacProvider::FISCAL_CODE, 'id' => 'cf-1'])
        ->and($user->getEntiSpid())->toBe(['isSpid' => 'true', 'spidCode' => 'TEST0000000001', 'id' => 'spid-1'])
        ->and($user->getEntiAcr())->toBe(['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'acr-1'])
        ->and($user->getEntiIssuerSource())->toBe(['issuerSource' => 'https://idp.test', 'id' => 'issuer-1']);
});

it('round-trips through toArray and JSON', function () {
    $user = SpidTrentinoUser::fromArray(FakeAacProvider::userInfo());

    expect(SpidTrentinoUser::fromArray($user->toArray())->toArray())->toBe($user->toArray())
        ->and(json_encode($user))->toBe(json_encode($user->toArray()))
        ->and($user->toArray())->toHaveKeys(['sub', 'email', 'enti-codicefiscale', 'family_name']);
});

it('hydrates from nested stdClass objects as returned by jumbojett', function () {
    $payload = json_decode((string) json_encode(FakeAacProvider::userInfo()));

    expect(SpidTrentinoUser::fromStdClass($payload)->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and((new SpidTrentinoUser($payload))->getEmail())->toBe('mario.rossi@example.com');
});

it('hydrates from JSON', function () {
    expect(SpidTrentinoUser::fromJson((string) json_encode(FakeAacProvider::userInfo()))->getSub())->toBe('subject-123');
});

it('rejects invalid JSON unless asked not to throw', function (string $json) {
    expect(fn () => SpidTrentinoUser::fromJson($json))->toThrow(InvalidArgumentException::class)
        ->and(SpidTrentinoUser::fromJson($json, false)->toArray())->toBe((new SpidTrentinoUser)->toArray());
})->with(['malformed' => '{"sub":', 'not an object' => '"just a string"']);

it('ignores claims of unexpected types instead of failing', function () {
    $user = SpidTrentinoUser::fromArray([
        'sub' => 12345,
        'email' => ['not', 'a', 'string'],
        'locale' => null,
        'enti-codicefiscale' => 'TINIT-RSSMRA80A01H501U',
    ]);

    expect($user->getSub())->toBe('12345')
        ->and($user->getEmail())->toBe('')
        ->and($user->getLocale())->toBe('')
        ->and($user->getEntiCodiceFiscale())->toBe([])
        ->and($user->getFiscalNumber())->toBe('');
});

it('exposes fluent setters', function () {
    $user = (new SpidTrentinoUser)
        ->setSub('s')->setZoneinfo('z')->setPreferredUsername('p')->setLocale('l')
        ->setGivenName('g')->setRealm('r')->setId('i')->setFamilyName('f')->setEmail('e@example.com')
        ->setEntiIssuerSource((object) ['issuerSource' => 'x'])->setEntiAcr(['acr' => 'y'])
        ->setEntiSpid(['isSpid' => 'true'])->setEntiCodiceFiscale(['fiscalCode' => 'TINIT-X']);

    expect($user->toArray())->toBe([
        'sub' => 's', 'zoneinfo' => 'z', 'enti-issuersource' => ['issuerSource' => 'x'],
        'preferred_username' => 'p', 'locale' => 'l', 'given_name' => 'g', 'email' => 'e@example.com',
        'enti-acr' => ['acr' => 'y'], 'enti-spid' => ['isSpid' => 'true'], 'realm' => 'r',
        'enti-codicefiscale' => ['fiscalCode' => 'TINIT-X'], 'id' => 'i', 'family_name' => 'f',
    ]);
});
