<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

/**
 * Health check of the transaction log, meant to be scheduled with an alert on
 * failure: the table exists, rows keep being written while users log in, and
 * the newest rows pass their integrity check.
 */
class CheckSpidTransactionLogs extends Command
{
    /** Rows of a login are written while the callback runs, before the login marker. */
    private const int GRACE_SECONDS = 300;

    protected $signature = 'spid:check-logs
        {--max-age=24h : Window in which a successful login must have produced log rows (minutes, hours or days: 30m, 24h, 7d)}
        {--sample=20 : Number of newest rows whose integrity is verified}';

    protected $description = 'Check that the SPID transaction log is being written and that its newest rows verify.';

    public function handle(): int
    {
        $maxAge = $this->stringOption('max-age');
        $minutes = $this->minutes($maxAge);
        $sample = $this->stringOption('sample');

        if ($minutes === null) {
            $this->error("Invalid --max-age [{$maxAge}]; use minutes, hours or days, for example 30m, 24h or 7d.");

            return self::INVALID;
        }

        if (preg_match('/^\d+$/', $sample) !== 1) {
            $this->error("Invalid --sample [{$sample}]; use a number of rows, 0 or more.");

            return self::INVALID;
        }

        if (! filter_var(Config::get('spid-laravel-trentino.transaction_log.enabled', true), FILTER_VALIDATE_BOOL)) {
            $this->error('The transaction log is disabled (transaction_log.enabled).');

            return self::FAILURE;
        }

        $model = new SpidTransactionLog;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $this->error("The transaction log table [{$model->getTable()}] does not exist.");

            return self::FAILURE;
        }

        return $this->checkWrites($maxAge, $minutes) && $this->checkSample((int) $sample)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function checkWrites(string $maxAge, int $minutes): bool
    {
        $lastLogin = Cache::get(SpidTrentino::LAST_LOGIN_CACHE_KEY);
        $lastLogin = is_int($lastLogin) ? CarbonImmutable::createFromTimestamp($lastLogin, CarbonImmutable::now()->getTimezone()) : null;

        if ($lastLogin === null || $lastLogin->lessThan(CarbonImmutable::now()->subMinutes($minutes))) {
            $this->warn("No SPID login in the last {$maxAge}: cannot confirm that new rows are written.");

            return true;
        }

        $newest = SpidTransactionLog::query()->max('created_at');
        $newest = is_string($newest) ? CarbonImmutable::parse($newest) : null;

        if ($newest === null || $newest->lessThan($lastLogin->subSeconds(self::GRACE_SECONDS))) {
            $this->error(sprintf(
                'A SPID login succeeded at %s but no transaction log row was written since %s.',
                $lastLogin->format('Y-m-d H:i:s'),
                $newest?->format('Y-m-d H:i:s') ?? 'ever',
            ));

            return false;
        }

        return true;
    }

    private function checkSample(int $sample): bool
    {
        $rows = $sample > 0 ? SpidTransactionLog::query()->orderByDesc('id')->limit($sample)->get() : [];

        foreach ($rows as $row) {
            try {
                $intact = $row->verifyIntegrity();
            } catch (DecryptException) {
                $intact = false;
            }

            if (! $intact) {
                $this->error("Row #{$row->id} failed the integrity check.");

                return false;
            }
        }

        $this->info('The SPID transaction log is healthy: '.count($rows).' sampled row(s) verified.');

        return true;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private function minutes(string $maxAge): ?int
    {
        if (preg_match('/^([1-9]\d*)([mhd])$/', $maxAge, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * ['m' => 1, 'h' => 60, 'd' => 1440][$match[2]];
    }
}
