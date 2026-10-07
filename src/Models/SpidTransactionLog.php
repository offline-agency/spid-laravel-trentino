<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * One OIDC message of a SPID login, kept for the retention period required by
 * the SPID/CIE OIDC log management rules: the JSON payload is encrypted at
 * rest with the application key, and an HMAC-SHA256 of the plaintext (keyed
 * with the application key) guards its integrity.
 *
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
 */
class SpidTransactionLog extends Model
{
    public const array EVENT_TYPES = [
        'authentication_request',
        'authentication_response',
        'authentication_rejected',
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
            'payload_hmac' => self::hmac($json),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
        $record->save();

        return $record;
    }

    /**
     * True when the decrypted payload still matches the HMAC written with it.
     */
    public function verifyIntegrity(): bool
    {
        return hash_equals(self::hmac($this->payload), $this->payload_hmac);
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

    private static function hmac(string $json): string
    {
        $key = Config::get('app.key');

        return hash_hmac('sha256', $json, is_string($key) ? $key : '');
    }
}
