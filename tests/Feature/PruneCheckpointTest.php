<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\PruneSpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

mutates(PruneSpidTransactionLogs::class);

function logAt(string $date, string $transactionId): SpidTransactionLog
{
    test()->travelTo(CarbonImmutable::parse($date));

    return SpidTransactionLog::log($transactionId, 'logout', []);
}

it('records the last pruned row as a checkpoint', function () {
    logAt('2023-01-01', 'old-1');
    $lastPruned = logAt('2023-02-01', 'old-2');
    logAt('2026-01-01', 'new');

    $this->artisan('spid:prune-logs')->assertSuccessful();

    $checkpoint = DB::table(SpidTransactionLog::checkpointsTable())->sole();
    expect((int) $checkpoint->last_id)->toBe($lastPruned->getKey())
        ->and($checkpoint->last_chain_hash)->toBe($lastPruned->chain_hash)
        ->and((int) $checkpoint->deleted)->toBe(2)
        ->and(SpidTransactionLog::query()->pluck('transaction_id')->all())->toBe(['new']);
});

it('deletes a contiguous prefix only, keeping expired rows written after a kept one', function () {
    logAt('2023-01-01', 'old');
    logAt('2026-01-01', 'kept');
    logAt('2023-03-01', 'late-but-old-dated'); // e.g. clock skew between servers
    $this->travelTo(CarbonImmutable::parse('2026-01-02'));

    $this->artisan('spid:prune-logs')
        ->expectsOutputToContain('Deleted 1 SPID transaction log record(s)')
        ->assertSuccessful();

    expect(SpidTransactionLog::query()->orderBy('id')->pluck('transaction_id')->all())->toBe(['kept', 'late-but-old-dated']);
});

it('counts the same contiguous prefix in a dry run and writes no checkpoint', function () {
    logAt('2023-01-01', 'old');
    logAt('2026-01-01', 'kept');
    logAt('2023-03-01', 'late');
    $this->travelTo(CarbonImmutable::parse('2026-01-02'));

    $this->artisan('spid:prune-logs', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 record(s)')
        ->assertSuccessful();

    expect(DB::table(SpidTransactionLog::checkpointsTable())->count())->toBe(0)
        ->and(SpidTransactionLog::query()->count())->toBe(3);
});

it('writes no checkpoint when nothing is expired', function () {
    logAt('2026-01-01', 'new');

    $this->artisan('spid:prune-logs')->expectsOutputToContain('Deleted 0')->assertSuccessful();

    expect(DB::table(SpidTransactionLog::checkpointsTable())->count())->toBe(0);
});

it('prunes everything when every row is expired', function () {
    logAt('2023-01-01', 'a');
    $last = logAt('2023-02-01', 'b');
    $this->travelTo(CarbonImmutable::parse('2026-01-01'));

    $this->artisan('spid:prune-logs')->assertSuccessful();

    expect(SpidTransactionLog::query()->count())->toBe(0)
        ->and(DB::table(SpidTransactionLog::checkpointsTable())->value('last_chain_hash'))->toBe($last->chain_hash);
});
