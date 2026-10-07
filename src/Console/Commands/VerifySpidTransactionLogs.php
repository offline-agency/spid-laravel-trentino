<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\Concerns\ParsesDateOptions;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogVerifier;

class VerifySpidTransactionLogs extends Command
{
    use ParsesDateOptions;

    protected $signature = 'spid:verify-logs
        {--from= : First day to verify (YYYY-MM-DD; default: the oldest row)}
        {--to= : Last day to verify (YYYY-MM-DD; default: the newest row, and the chain head is checked)}';

    protected $description = 'Verify the HMAC and the hash chain of the SPID transaction log.';

    public function handle(TransactionLogVerifier $verifier): int
    {
        try {
            $from = $this->dateOption('from');
            $to = $this->dateOption('to')?->endOfDay();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return self::INVALID;
        }

        $result = $verifier->verify($from, $to);

        if (! $result->ok) {
            $this->error("Row #{$result->brokenAt} failed verification: {$result->reason}");

            return self::FAILURE;
        }

        $this->info("Verified {$result->checked} row(s), {$result->legacy} legacy (HMAC only). The transaction log chain is intact.");

        return self::SUCCESS;
    }
}
