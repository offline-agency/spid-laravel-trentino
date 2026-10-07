<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

/**
 * Hash chain of the transaction log: every row stores the previous row's
 * chain hash and its own, computed over the previous hash, the payload HMAC
 * and the search fields. The hash is plain SHA-256 so anyone holding the rows
 * (and the published digests) can verify the linkage without any secret.
 *
 * Appends are serialized by a single-row head table: each append starts with
 * a write to that row, which takes the row lock on MySQL and PostgreSQL and
 * the database write lock on SQLite before the head is read, so two
 * concurrent logins can never link to the same previous row.
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

            $head = $connection->table($heads)->where('id', self::HEAD_ID)->value('head_hash');
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
            'iat' => self::timestamp($row->iat),
            'exp' => self::timestamp($row->exp),
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'key_id' => $row->key_id,
            'created_at' => self::timestamp($row->created_at),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function timestamp(?DateTimeInterface $value): ?int
    {
        return $value?->getTimestamp();
    }
}
