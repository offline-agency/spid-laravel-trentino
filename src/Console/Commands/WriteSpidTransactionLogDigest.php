<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\Concerns\ParsesDateOptions;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogKeys;

/**
 * Writes the day's last chain hash to a disk the application cannot rewrite
 * (for example S3 with Object Lock): rewriting the log after that day then
 * produces a chain that no longer matches the published digest.
 */
class WriteSpidTransactionLogDigest extends Command
{
    use ParsesDateOptions;

    protected $signature = 'spid:log-digest
        {--date= : Day to digest (YYYY-MM-DD; default: yesterday)}';

    protected $description = 'Write the daily digest of the SPID transaction log hash chain to the digest disk.';

    public function handle(): int
    {
        $disk = Config::get('spid-laravel-trentino.transaction_log.digest_disk');

        if (! is_string($disk) || $disk === '') {
            $this->info('Transaction log digests are disabled (set transaction_log.digest_disk to enable them).');

            return self::SUCCESS;
        }

        try {
            $day = $this->dateOption('date') ?? CarbonImmutable::yesterday();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if (! $day->lessThan(CarbonImmutable::today())) {
            $this->error('--date must be a day before today.');

            return self::INVALID;
        }

        $path = 'spid-transaction-log/digests/'.$day->format('Y-m-d').'.json';
        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $this->warn("{$path} already exists; not overwritten.");

            return self::SUCCESS;
        }

        $digest = $this->digest($day);
        $storage->put($path, json_encode($digest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Wrote {$path} ({$digest['rows']} row(s)).");

        return self::SUCCESS;
    }

    /**
     * @return array{date: string, rows: int, first_id: ?int, last_id: ?int, last_chain_hash: ?string, key_ids: list<string>, generated_at: string}
     */
    private function digest(CarbonImmutable $day): array
    {
        $rows = SpidTransactionLog::query()->whereBetween('created_at', [$day, $day->endOfDay()]);

        $first = (clone $rows)->orderBy('id')->first();
        $last = (clone $rows)->orderByDesc('id')->first();
        $keyIds = array_values((clone $rows)->distinct()->pluck('key_id')
            ->map(fn (mixed $id): string => is_string($id) ? $id : TransactionLogKeys::APP)
            ->unique()->sort()->all());

        return [
            'date' => $day->format('Y-m-d'),
            'rows' => $rows->count(),
            'first_id' => $first?->id,
            'last_id' => $last?->id,
            'last_chain_hash' => $last?->chain_hash,
            'key_ids' => $keyIds,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
