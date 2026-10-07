<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogChain;
use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogKeys;

/**
 * One OIDC message of a SPID login, kept for the retention period required by
 * the SPID/CIE OIDC log management rules: the JSON payload is encrypted at
 * rest with the application key, and an HMAC-SHA256 of the plaintext (keyed
 * with the application key) guards its integrity.
 *
 * @property int $id
 * @property string $transaction_id
 * @property string $event_type
 * @property string|null $authorization_code
 * @property string|null $client_id
 * @property string|null $jti
 * @property string|null $iss
 * @property string|null $sub
 * @property CarbonImmutable|null $iat
 * @property CarbonImmutable|null $exp
 * @property string $payload
 * @property string $payload_hmac
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $key_id HMAC key id; null for rows written before versioned keys (APP_KEY)
 * @property string|null $previous_hash
 * @property string|null $chain_hash
 * @property CarbonImmutable|null $created_at
 */
class SpidTransactionLog extends Model
{
    public const array EVENT_TYPES = [
        'authentication_request',
        'authentication_response',
        'token_request',
        'token_response',
        'userinfo_request',
        'userinfo_response',
        'revocation_request',
        'revocation_response',
        'refresh_request',
        'refresh_response',
        'logout',
    ];

    public function getTable(): string
    {
        $table = Config::get('spid-laravel-trentino.transaction_log.table');

        return is_string($table) && $table !== '' ? $table : 'spid_transaction_logs';
    }

    /**
     * Single-row table holding the head of the hash chain.
     */
    public static function headsTable(): string
    {
        return (new self)->getTable().'_heads';
    }

    /**
     * Checkpoints written by spid:prune-logs, used as chain anchors.
     */
    public static function checkpointsTable(): string
    {
        return (new self)->getTable().'_checkpoints';
    }

    /**
     * @param  array<array-key, mixed>  $payload  the OIDC message, stored encrypted
     *
     * @throws InvalidArgumentException for an unknown event type
     */
    public static function log(
        string $transactionId,
        string $eventType,
        array $payload,
        ?string $authorizationCode = null,
        ?string $clientId = null,
        ?string $jti = null,
        ?string $iss = null,
        ?string $sub = null,
        ?DateTimeInterface $iat = null,
        ?DateTimeInterface $exp = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException("Invalid SPID transaction event type [{$eventType}].");
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        [$keyId, $secret] = TransactionLogKeys::current();

        $record = new self;
        $record->forceFill([
            'transaction_id' => $transactionId,
            'event_type' => $eventType,
            'authorization_code' => $authorizationCode,
            'client_id' => $clientId,
            'jti' => $jti,
            'iss' => $iss,
            'sub' => $sub,
            'iat' => $iat,
            'exp' => $exp,
            'payload' => $json,
            'payload_hmac' => hash_hmac('sha256', $json, $secret),
            'key_id' => $keyId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return TransactionLogChain::append($record);
    }

    /**
     * True when the decrypted payload still matches the HMAC written with it.
     */
    public function verifyIntegrity(): bool
    {
        $secret = TransactionLogKeys::secret($this->key_id);

        return $secret !== null && hash_equals(hash_hmac('sha256', $this->payload, $secret), $this->payload_hmac);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payloadData(): array
    {
        $data = json_decode($this->payload, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTransaction(Builder $query, string $transactionId): Builder
    {
        return $query->where('transaction_id', $transactionId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query, int $months): Builder
    {
        return $query->where('created_at', '<', CarbonImmutable::now()->subMonths($months));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'iat' => 'immutable_datetime',
            'exp' => 'immutable_datetime',
        ];
    }
}
