<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

/**
 * Hash chain of the transaction log: every row stores the previous row's
 * chain hash and its own, computed over the previous hash, the payload HMAC
 * and the search fields. The hash is plain SHA-256 so anyone holding the rows
 * (and the published digests) can verify the linkage without any secret.
 *
 * Appends are serialized by a single-row head table (seeded by the
 * migration): each append starts with a write to that row, which takes the
 * row lock on MySQL and PostgreSQL and the database write lock on SQLite, then
 * reads the head with a locking read, which returns the latest committed
 * head even under REPEATABLE READ. Two concurrent logins can therefore never
 * link to the same previous row. The lock is held until the surrounding
 * transaction commits.
 */
final class TransactionLogChain
{
    private const int HEAD_ID = 1;

    private const int ATTEMPTS = 3;

    public static function genesis(): string
    {
        return hash('sha256', 'spid-laravel-trentino:genesis');
    }

    public static function link(string $previousHash, SpidTransactionLog $row): string
    {
        return hash('sha256', $previousHash."\n".$row->payload_hmac."\n".self::canonical($row));
    }

    /**
     * Saves the row as the new head of the chain.
     */
    public static function append(SpidTransactionLog $row): SpidTransactionLog
    {
        $connection = $row->getConnection();

        return $connection->transaction(function () use ($row, $connection): SpidTransactionLog {
            $now = CarbonImmutable::now()->startOfSecond();
            $heads = SpidTransactionLog::headsTable();

            // Write first: acquires the lock before the head is read.
            if ($connection->table($heads)->where('id', self::HEAD_ID)->update(['updated_at' => $now]) === 0) {
                $connection->table($heads)->insertOrIgnore(['id' => self::HEAD_ID, 'created_at' => $now, 'updated_at' => $now]);
            }

            $head = self::headQuery($connection)->value('head_hash');
            $previous = is_string($head) ? $head : self::genesis();

            $row->setCreatedAt($now);
            $row->setUpdatedAt($now);
            $row->setAttribute('previous_hash', $previous);
            $row->setAttribute('chain_hash', self::link($previous, $row));
            $row->save();

            $connection->table($heads)->where('id', self::HEAD_ID)->update([
                'head_id' => $row->getKey(),
                'head_hash' => $row->chain_hash,
            ]);

            return $row;
        }, self::ATTEMPTS);
    }

    /**
     * Locking read of the chain head.
     */
    public static function headQuery(ConnectionInterface $connection): Builder
    {
        return $connection->table(SpidTransactionLog::headsTable())->where('id', self::HEAD_ID)->lockForUpdate();
    }

    private static function canonical(SpidTransactionLog $row): string
    {
        return json_encode([
            'transaction_id' => $row->transaction_id,
            'event_type' => $row->event_type,
            'authorization_code' => $row->authorization_code,
            'client_id' => $row->client_id,
            'jti' => $row->jti,
            'iss' => $row->iss,
            'sub' => $row->sub,
            'iat' => self::date($row, $row->iat),
            'exp' => self::date($row, $row->exp),
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'key_id' => $row->key_id,
            'created_at' => self::date($row, $row->created_at),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The date as stored in the database: unlike a unix timestamp it does not
     * change when the application timezone changes.
     */
    private static function date(SpidTransactionLog $row, ?DateTimeInterface $value): ?string
    {
        return $value === null ? null : $row->fromDateTime($value);
    }
}
