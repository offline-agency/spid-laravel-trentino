<?php

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Illuminate\Console\Command;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

class PruneSpidTransactionLogs extends Command
{
    protected $signature = 'spid:prune-logs
                            {--months=24 : Number of months to retain (minimum 24, per SPID regulations)}
                            {--dry-run   : Count records that would be deleted without actually deleting them}';

    protected $description = 'Prune SPID transaction log records older than the configured retention period (minimum 24 months).';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $dryRun = $this->option('dry-run');

        if ($months < 24) {
            $this->warn('SPID regulations require a minimum retention of 24 months. Clamping to 24.');
            $months = 24;
        }

        $query = SpidTransactionLog::query()->expired($months);

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would delete {$count} record(s) older than {$months} months (dry-run, no changes made).");

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->chunkById(1000, function ($records) use (&$deleted) {
            $ids = $records->pluck('id')->all();
            $count = SpidTransactionLog::whereIn('id', $ids)->delete();
            $deleted += $count;
        });

        $this->info("Deleted {$deleted} SPID transaction log record(s) older than {$months} months.");

        return self::SUCCESS;
    }
}
