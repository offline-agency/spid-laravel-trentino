<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

class PruneSpidTransactionLogs extends Command
{
    /** Minimum retention required by the SPID/CIE OIDC log management rules. */
    private const int MINIMUM_MONTHS = 24;

    protected $signature = 'spid:prune-logs
        {--months= : Months to retain (default: transaction_log.retention_months; never below 24)}
        {--dry-run : Count the records that would be deleted without deleting them}';

    protected $description = 'Delete SPID transaction log records older than the retention period (minimum 24 months).';

    public function handle(): int
    {
        $months = $this->retentionMonths();

        if ($months < self::MINIMUM_MONTHS) {
            $this->warn('SPID regulations require a minimum retention of 24 months. Using 24.');
            $months = self::MINIMUM_MONTHS;
        }

        $query = SpidTransactionLog::query()->expired($months);

        if ($this->option('dry-run') === true) {
            $this->info("Would delete {$query->count()} record(s) older than {$months} months (dry run, nothing deleted).");

            return self::SUCCESS;
        }

        $expired = $query->count();
        $query->delete();
        $this->info("Deleted {$expired} SPID transaction log record(s) older than {$months} months.");

        return self::SUCCESS;
    }

    private function retentionMonths(): int
    {
        $option = $this->option('months');

        if (is_numeric($option)) {
            return (int) $option;
        }

        $configured = Config::get('spid-laravel-trentino.transaction_log.retention_months');

        return is_numeric($configured) ? (int) $configured : self::MINIMUM_MONTHS;
    }
}
