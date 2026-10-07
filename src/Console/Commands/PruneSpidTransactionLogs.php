<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Carbon\CarbonImmutable;
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

        $lastId = $this->lastPrunableId($months);
        $query = SpidTransactionLog::query()->where('id', '<=', $lastId);

        if ($this->option('dry-run') === true) {
            $this->info("Would delete {$query->count()} record(s) older than {$months} months (dry run, nothing deleted).");

            return self::SUCCESS;
        }

        $deleted = $query->count();

        if ($deleted > 0) {
            $this->deleteWithCheckpoint($lastId, $deleted);
        }

        $this->info("Deleted {$deleted} SPID transaction log record(s) older than {$months} months.");

        return self::SUCCESS;
    }

    /**
     * Highest id of the contiguous run of expired rows at the start of the
     * log: rows after the first row that must be kept stay, even if they look
     * expired (clock skew), so the remaining chain has no holes.
     */
    private function lastPrunableId(int $months): int
    {
        $firstKept = SpidTransactionLog::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subMonths($months))
            ->min('id');

        $candidates = SpidTransactionLog::query()->expired($months);

        if (is_numeric($firstKept)) {
            $candidates->where('id', '<', (int) $firstKept);
        }

        $lastId = $candidates->max('id');

        return is_numeric($lastId) ? (int) $lastId : 0;
    }

    /**
     * Deletes the prefix and records its last chain hash as the anchor that
     * spid:verify-logs starts from.
     */
    private function deleteWithCheckpoint(int $lastId, int $deleted): void
    {
        $connection = (new SpidTransactionLog)->getConnection();

        $connection->transaction(function () use ($connection, $lastId, $deleted): void {
            $last = SpidTransactionLog::query()->findOrFail($lastId);

            $connection->table(SpidTransactionLog::checkpointsTable())->insert([
                'last_id' => $lastId,
                'last_chain_hash' => $last->chain_hash,
                'last_created_at' => $last->created_at,
                'deleted' => $deleted,
                'created_at' => CarbonImmutable::now(),
            ]);

            SpidTransactionLog::query()->where('id', '<=', $lastId)->delete();
        });
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
