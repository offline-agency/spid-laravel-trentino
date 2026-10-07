<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use OfflineAgency\SpidLaravelTrentino\Support\FiscalCode;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;

/**
 * Rewrites the fiscal_code of existing users in the normalized form used for
 * matching since 3.0. All or nothing: when two users would end up with the
 * same fiscal code, nothing changes and the users are listed (by user id and
 * a keyed hash of the code, never the code itself) for manual resolution.
 */
class NormalizeFiscalCodes extends Command
{
    private const int CHUNK = 500;

    protected $signature = 'spid:normalize-fiscal-codes
        {--dry-run : Report the changes without writing them}';

    protected $description = 'Normalize the fiscal codes of existing users (uppercase, no TINIT- prefix), refusing to create duplicates.';

    public function handle(): int
    {
        $userModel = Config::get('auth.providers.users.model');
        $userModel = is_string($userModel) ? $userModel : '';

        if (! is_a($userModel, Model::class, true)) {
            $this->error("The user model [{$userModel}] must be an Eloquent model.");

            return self::FAILURE;
        }

        /** @var array<int|string, string> $changes user key => normalized code */
        $changes = [];
        /** @var array<string, list<int|string>> $owners normalized code => user keys */
        $owners = [];
        $model = new $userModel;

        foreach ($userModel::query()->whereNotNull('fiscal_code')->lazyById(self::CHUNK, $model->getKeyName()) as $user) {
            $current = $user->getAttribute('fiscal_code');
            $normalized = FiscalCode::normalize(is_string($current) ? $current : '');

            if ($normalized === '') {
                continue;
            }

            $key = $user->getKey();
            $key = is_int($key) || is_string($key) ? $key : (string) json_encode($key);
            $owners[$normalized][] = $key;

            if ($normalized !== $current) {
                $changes[$key] = $normalized;
            }
        }

        $conflicts = array_filter($owners, fn (array $keys): bool => count($keys) > 1);

        if ($conflicts !== []) {
            $this->error('Normalizing would give one fiscal code to several users; nothing was changed. Merge or fix these users first:');

            foreach ($conflicts as $code => $keys) {
                $this->line('  '.LogRedactor::hash((string) $code).': users '.implode(', ', $keys));
            }

            return self::FAILURE;
        }

        if ($changes === []) {
            $this->info('All fiscal codes are already normalized.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            $this->info('Would normalize '.count($changes).' fiscal code(s) (dry run, nothing changed).');

            return self::SUCCESS;
        }

        $model->getConnection()->transaction(function () use ($userModel, $changes): void {
            foreach ($changes as $key => $normalized) {
                $userModel::query()->whereKey($key)->update(['fiscal_code' => $normalized]);
            }
        });

        $this->info('Normalized '.count($changes).' fiscal code(s).');

        return self::SUCCESS;
    }
}
