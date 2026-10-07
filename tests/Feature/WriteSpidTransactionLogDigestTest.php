<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\WriteSpidTransactionLogDigest;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

mutates(WriteSpidTransactionLogDigest::class);

beforeEach(function () {
    Config::set('spid-laravel-trentino.transaction_log.digest_disk', 'worm');
    Storage::fake('worm');
});

function digestRow(string $at, string $transactionId): SpidTransactionLog
{
    test()->travelTo(CarbonImmutable::parse($at));

    return SpidTransactionLog::log($transactionId, 'logout', []);
}

/**
 * @return array<string, mixed>
 */
function digest(string $date): array
{
    return json_decode((string) Storage::disk('worm')->get("spid-transaction-log/digests/{$date}.json"), true, flags: JSON_THROW_ON_ERROR);
}

it("writes the day's last chain hash and row count to the disk", function () {
    digestRow('2026-03-01 23:59:59', 'before');
    $first = digestRow('2026-03-02 08:00', 'tx-1');
    Config::set('spid-laravel-trentino.transaction_log.keys', 'k2:'.str_repeat('a', 32));
    Config::set('spid-laravel-trentino.transaction_log.current_key', 'k2');
    $last = digestRow('2026-03-02 20:00', 'tx-2');
    digestRow('2026-03-03 00:00', 'after');
    $this->travelTo(CarbonImmutable::parse('2026-03-05 01:00'));

    $this->artisan('spid:log-digest', ['--date' => '2026-03-02'])
        ->expectsOutputToContain('Wrote spid-transaction-log/digests/2026-03-02.json (2 row(s)).')
        ->assertSuccessful();

    expect(digest('2026-03-02'))->toBe([
        'date' => '2026-03-02',
        'rows' => 2,
        'first_id' => $first->id,
        'last_id' => $last->id,
        'last_chain_hash' => $last->chain_hash,
        'key_ids' => ['app', 'k2'],
        'generated_at' => '2026-03-05T01:00:00+00:00',
    ]);
});

it('defaults to yesterday', function () {
    $row = digestRow('2026-03-02 12:00', 'tx-1');
    $this->travelTo(CarbonImmutable::parse('2026-03-03 00:30'));

    $this->artisan('spid:log-digest')->assertSuccessful();

    expect(digest('2026-03-02')['last_chain_hash'])->toBe($row->chain_hash);
});

it('is disabled without a disk', function () {
    Config::set('spid-laravel-trentino.transaction_log.digest_disk', null);

    $this->artisan('spid:log-digest')
        ->expectsOutputToContain('Transaction log digests are disabled')
        ->assertSuccessful();

    expect(Storage::disk('worm')->allFiles())->toBe([]);
});

it('writes an empty digest for a day without rows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-05'));

    $this->artisan('spid:log-digest', ['--date' => '2026-03-02'])->assertSuccessful();

    expect(digest('2026-03-02'))->toMatchArray([
        'rows' => 0, 'first_id' => null, 'last_id' => null, 'last_chain_hash' => null, 'key_ids' => [],
    ]);
});

it('never overwrites an existing digest', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-05'));
    Storage::disk('worm')->put('spid-transaction-log/digests/2026-03-02.json', 'original');

    $this->artisan('spid:log-digest', ['--date' => '2026-03-02'])
        ->expectsOutputToContain('already exists; not overwritten')
        ->assertSuccessful();

    expect(Storage::disk('worm')->get('spid-transaction-log/digests/2026-03-02.json'))->toBe('original');
});

it('rejects an invalid date', function (string $date, string $message) {
    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00'));

    $this->artisan('spid:log-digest', ['--date' => $date])->expectsOutputToContain($message)->assertExitCode(2);

    expect(Storage::disk('worm')->allFiles())->toBe([]);
})->with([
    'not a date' => ['last week', 'Invalid --date date [last week]; expected YYYY-MM-DD.'],
    'today' => ['2026-03-05', '--date must be a day before today.'],
    'future' => ['2026-03-06', '--date must be a day before today.'],
]);
