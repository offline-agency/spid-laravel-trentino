<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

/**
 * Walks the transaction log in id order and checks every row: its HMAC, its
 * link to the preceding row and its own chain hash. Rows written before the
 * hash chain existed (no chain hash) are legacy: only their HMAC is checked,
 * and they may only appear before the first chained row.
 *
 * A date range is turned into a contiguous id range; its first row is checked
 * against the row before it, or against the newest prune checkpoint when
 * that row was pruned. Without an end date, the last row must also match the
 * chain head, so deleting the newest rows is detected too.
 */
final class TransactionLogVerifier
{
    private const int CHUNK = 500;

    private bool $started = false;

    private string $expected = '';

    public function verify(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): VerificationResult
    {
        // Captured first: rows appended while the log is walked are not missing rows.
        $head = $to === null ? $this->head() : null;
        $firstId = $this->firstId($from);
        $lastId = $this->lastId($to);
        $this->anchor($firstId);

        $checked = 0;
        $legacy = 0;

        $rows = SpidTransactionLog::query()
            ->where('id', '>=', $firstId)
            ->where('id', '<=', $lastId)
            ->lazyById(self::CHUNK);

        foreach ($rows as $row) {
            $checked++;
            $problem = $this->problem($row);

            if ($problem !== null) {
                return new VerificationResult(false, $checked, $legacy, $row->id, $problem);
            }

            if ($row->chain_hash === null) {
                $legacy++;
            }
        }

        $missing = $head === null ? null : $this->missingHead(...$head);

        if ($missing !== null) {
            return new VerificationResult(false, $checked, $legacy, $missing, 'the newest rows are missing: the chain head does not match the last row.');
        }

        return new VerificationResult(true, $checked, $legacy);
    }

    private function firstId(?CarbonImmutable $from): int
    {
        $query = SpidTransactionLog::query();

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $id = $query->min('id');

        return is_numeric($id) ? (int) $id : $this->maxId() + 1;
    }

    private function lastId(?CarbonImmutable $to): int
    {
        if ($to === null) {
            return $this->maxId();
        }

        $id = SpidTransactionLog::query()->where('created_at', '<=', $to)->max('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function maxId(): int
    {
        $id = SpidTransactionLog::query()->max('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Sets the chain hash the first checked row must link to: the row before
     * it, else the newest prune checkpoint, else none (the chain has not
     * started: the first chained row links to the genesis hash).
     */
    private function anchor(int $firstId): void
    {
        $previous = SpidTransactionLog::query()->where('id', '<', $firstId)->orderByDesc('id')->first();

        $hash = $previous !== null
            ? $previous->chain_hash
            : $this->table(SpidTransactionLog::checkpointsTable())->orderByDesc('id')->value('last_chain_hash');

        $this->started = is_string($hash);
        $this->expected = is_string($hash) ? $hash : TransactionLogChain::genesis();
    }

    private function problem(SpidTransactionLog $row): ?string
    {
        $integrity = $this->integrityProblem($row);

        if ($integrity !== null) {
            return $integrity;
        }

        if ($row->chain_hash === null) {
            return $this->started ? 'the row is not chained.' : null;
        }

        if ($row->previous_hash !== $this->expected) {
            return 'the previous hash does not match the preceding row.';
        }

        if (! hash_equals(TransactionLogChain::link($this->expected, $row), $row->chain_hash)) {
            return 'the chain hash does not match the row.';
        }

        $this->started = true;
        $this->expected = $row->chain_hash;

        return null;
    }

    private function integrityProblem(SpidTransactionLog $row): ?string
    {
        if (TransactionLogKeys::secrets($row->key_id) === []) {
            return 'the HMAC key ['.($row->key_id ?? TransactionLogKeys::APP).'] is not configured.';
        }

        try {
            return $row->verifyIntegrity() ? null : 'the payload does not match its HMAC.';
        } catch (DecryptException) {
            return 'the payload cannot be decrypted.';
        }
    }

    /**
     * The chain head (id and hash of the newest chained row) when there is one.
     *
     * @return array{0: int, 1: string}|null
     */
    private function head(): ?array
    {
        $head = $this->table(SpidTransactionLog::headsTable())->first(['head_id', 'head_hash']);

        return $head !== null && is_numeric($head->head_id) && is_string($head->head_hash)
            ? [(int) $head->head_id, $head->head_hash]
            : null;
    }

    /**
     * Id of the chain head when its row no longer exists or no longer carries
     * the head hash: the newest rows were deleted or replaced.
     */
    private function missingHead(int $headId, string $headHash): ?int
    {
        return SpidTransactionLog::query()->find($headId)?->chain_hash === $headHash ? null : $headId;
    }

    private function table(string $table): Builder
    {
        return (new SpidTransactionLog)->getConnection()->table($table);
    }
}
