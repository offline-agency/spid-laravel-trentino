<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\CheckSpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

mutates(CheckSpidTransactionLogs::class);

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-03-02 12:00:00')));

function checkedRow(string $at): SpidTransactionLog
{
    $row = SpidTransactionLog::log('tx', 'authentication_response', ['code' => 'c']);
    DB::table('spid_transaction_logs')->where('id', $row->getKey())->update(['created_at' => CarbonImmutable::parse($at)]);

    return $row;
}

function lastLoginAt(string $at): void
{
    Cache::forever(SpidTrentino::LAST_LOGIN_CACHE_KEY, CarbonImmutable::parse($at)->getTimestamp());
}

it('passes on a healthy log', function () {
    checkedRow('2026-03-02 11:00:00');
    lastLoginAt('2026-03-02 11:00:05');

    $this->artisan('spid:check-logs')
        ->expectsOutputToContain('The SPID transaction log is healthy')
        ->assertSuccessful();
});

it('fails when the table is missing', function () {
    Schema::drop('spid_transaction_logs');

    $this->artisan('spid:check-logs')
        ->expectsOutputToContain('The transaction log table [spid_transaction_logs] does not exist.')
        ->assertFailed();
});

it('fails when the transaction log is disabled', function () {
    config()->set('spid-laravel-trentino.transaction_log.enabled', false);

    $this->artisan('spid:check-logs')
        ->expectsOutputToContain('The transaction log is disabled')
        ->assertFailed();
});

it('fails when logins happened but no row was written in the window', function (?string $newestRow) {
    if ($newestRow !== null) {
        checkedRow($newestRow);
    }
    lastLoginAt('2026-03-02 11:00:00');

    $this->artisan('spid:check-logs')
        ->expectsOutputToContain('A SPID login succeeded at 2026-03-02 11:00:00 but no transaction log row was written since '.($newestRow ?? 'ever').'.')
        ->assertFailed();
})->with(['stale rows' => '2026-03-01 09:00:00', 'no rows' => null]);

it('allows rows written up to five minutes before the login marker', function () {
    checkedRow('2026-03-02 10:55:00');
    lastLoginAt('2026-03-02 11:00:00');

    $this->artisan('spid:check-logs')->assertSuccessful();
});

it('warns but succeeds when there were no logins', function (?string $lastLogin) {
    checkedRow('2026-02-01 10:00:00');
    if ($lastLogin !== null) {
        lastLoginAt($lastLogin);
    }

    $this->artisan('spid:check-logs')
        ->expectsOutputToContain('No SPID login in the last 24h: cannot confirm that new rows are written.')
        ->assertSuccessful();
})->with(['never' => null, 'outside the window' => '2026-03-01 11:59:00']);

it('fails when a sampled row does not verify', function (string $column, string $value) {
    checkedRow('2026-03-02 10:00:00');
    $broken = checkedRow('2026-03-02 11:00:00');
    checkedRow('2026-03-02 11:30:00');
    DB::table('spid_transaction_logs')->where('id', $broken->getKey())->update([$column => $value]);

    $this->artisan('spid:check-logs', ['--sample' => 2])
        ->expectsOutputToContain("Row #{$broken->getKey()} failed the integrity check.")
        ->assertFailed();
})->with(['changed HMAC' => ['payload_hmac', str_repeat('0', 64)], 'undecryptable payload' => ['payload', 'garbage']]);

it('only samples the newest rows', function () {
    $old = checkedRow('2026-03-02 10:00:00');
    checkedRow('2026-03-02 11:00:00');
    DB::table('spid_transaction_logs')->where('id', $old->getKey())->update(['payload_hmac' => str_repeat('0', 64)]);

    $this->artisan('spid:check-logs', ['--sample' => 1])->assertSuccessful();
    $this->artisan('spid:check-logs', ['--sample' => 0])->expectsOutputToContain('0 sampled row(s) verified')->assertSuccessful();
});

it('parses --max-age in minutes, hours and days', function (string $maxAge, bool $failed) {
    checkedRow('2026-02-01 10:00:00');
    lastLoginAt('2026-03-02 10:30:00'); // 90 minutes ago, with no row since

    $command = $this->artisan('spid:check-logs', ['--max-age' => $maxAge]);

    $failed ? $command->assertFailed() : $command->expectsOutputToContain("No SPID login in the last {$maxAge}")->assertSuccessful();
})->with([
    '60 minutes' => ['60m', false],
    '120 minutes' => ['120m', true],
    '1 hour' => ['1h', false],
    '2 hours' => ['2h', true],
    '1 day' => ['1d', true],
]);

it('rejects invalid options', function (array $options, string $message) {
    $this->artisan('spid:check-logs', $options)->expectsOutputToContain($message)->assertExitCode(2);
})->with([
    'max-age text' => [['--max-age' => 'soon'], 'Invalid --max-age [soon]; use minutes, hours or days, for example 30m, 24h or 7d.'],
    'max-age zero' => [['--max-age' => '0h'], 'Invalid --max-age [0h]'],
    'max-age unit missing' => [['--max-age' => '5'], 'Invalid --max-age [5]'],
    'sample text' => [['--sample' => 'all'], 'Invalid --sample [all]; use a number of rows, 0 or more.'],
    'sample negative' => [['--sample' => '-1'], 'Invalid --sample [-1]'],
]);
