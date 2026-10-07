<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\NormalizeFiscalCodes;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;

mutates(NormalizeFiscalCodes::class);

function userWithFiscalCode(?string $fiscalCode): User
{
    return User::query()->forceCreate(['name' => 'User', 'fiscal_code' => $fiscalCode]);
}

/** @return array<int, ?string> */
function fiscalCodes(): array
{
    return User::query()->orderBy('id')->pluck('fiscal_code', 'id')->all();
}

it('normalizes existing fiscal codes', function () {
    $a = userWithFiscalCode('TINIT-RSSMRA80A01H501U');
    $b = userWithFiscalCode('vrdgpp70b02l219x');
    $c = userWithFiscalCode(' BNCLRA90C43F205Y ');

    $this->artisan('spid:normalize-fiscal-codes')
        ->expectsOutputToContain('Normalized 3 fiscal code(s).')
        ->assertSuccessful();

    expect(fiscalCodes())->toBe([
        $a->id => 'RSSMRA80A01H501U',
        $b->id => 'VRDGPP70B02L219X',
        $c->id => 'BNCLRA90C43F205Y',
    ]);
});

it('only reports with --dry-run', function () {
    userWithFiscalCode('TINIT-RSSMRA80A01H501U');
    userWithFiscalCode('VRDGPP70B02L219X');
    $before = fiscalCodes();

    $this->artisan('spid:normalize-fiscal-codes', ['--dry-run' => true])
        ->expectsOutputToContain('Would normalize 1 fiscal code(s) (dry run, nothing changed).')
        ->assertSuccessful();

    expect(fiscalCodes())->toBe($before);
});

it('changes nothing when normalization would create duplicates', function (bool $dryRun) {
    $prefixed = userWithFiscalCode('TINIT-RSSMRA80A01H501U');
    $lowercase = userWithFiscalCode('rssmra80a01h501u');
    $normalized = userWithFiscalCode('RSSMRA80A01H501U');
    userWithFiscalCode('TINIT-VRDGPP70B02L219X');
    $before = fiscalCodes();
    $output = [];

    $command = $this->artisan('spid:normalize-fiscal-codes', $dryRun ? ['--dry-run' => true] : [])
        ->expectsOutputToContain('Normalizing would give one fiscal code to several users; nothing was changed.')
        ->expectsOutputToContain(LogRedactor::hash('RSSMRA80A01H501U').": users {$prefixed->id}, {$lowercase->id}, {$normalized->id}")
        ->doesntExpectOutputToContain('RSSMRA80A01H501U')
        ->doesntExpectOutputToContain('rssmra80a01h501u');

    $command->assertExitCode(1);
    expect(fiscalCodes())->toBe($before);
})->with(['apply' => false, 'dry run' => true]);

it('skips users without a fiscal code', function (?string $empty) {
    $skipped = userWithFiscalCode($empty);
    $other = userWithFiscalCode('TINIT-RSSMRA80A01H501U');

    $this->artisan('spid:normalize-fiscal-codes')->expectsOutputToContain('Normalized 1 fiscal code(s).')->assertSuccessful();

    expect(fiscalCodes())->toBe([$skipped->id => $empty, $other->id => 'RSSMRA80A01H501U']);
})->with(['null' => null, 'empty' => '', 'prefix only' => 'TINIT-']);

it('is a no-op on already normalized data', function () {
    userWithFiscalCode('RSSMRA80A01H501U');
    userWithFiscalCode(null);
    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        $updates += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
    });

    $this->artisan('spid:normalize-fiscal-codes')
        ->expectsOutputToContain('All fiscal codes are already normalized.')
        ->assertSuccessful();

    expect($updates)->toBe(0);
});

it('rejects a non-Eloquent user model', function (string $model) {
    config()->set('auth.providers.users.model', $model);

    $this->artisan('spid:normalize-fiscal-codes')
        ->expectsOutputToContain("The user model [{$model}] must be an Eloquent model.")
        ->assertFailed();
})->with([stdClass::class, 'App\\Models\\Missing']);
