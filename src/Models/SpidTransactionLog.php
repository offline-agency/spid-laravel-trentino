<?php

namespace OfflineAgency\SpidLaravelTrentino\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SpidTransactionLog extends Model
{
    public const EVENT_TYPES = [
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

    protected $fillable = [
        'transaction_id',
        'event_type',
        'authorization_code',
        'client_id',
        'jti',
        'iss',
        'sub',
        'iat',
        'exp',
        'payload',
        'payload_hmac',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'encrypted',
        'iat'     => 'datetime',
        'exp'     => 'datetime',
    ];

    public function getTable(): string
    {
        return config('spid-laravel-trentino.transaction_log.table', 'spid_transaction_logs');
    }

    public static function log(
        string $transactionId,
        string $eventType,
        array $payload,
        ?string $authorizationCode = null,
        ?string $clientId = null,
        ?string $jti = null,
        ?string $iss = null,
        ?string $sub = null,
        ?\DateTimeInterface $iat = null,
        ?\DateTimeInterface $exp = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid SPID transaction event type: [{$eventType}]. Valid types: " . implode(', ', self::EVENT_TYPES)
            );
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hmac = hash_hmac('sha256', $jsonPayload, config('app.key'));

        $record = new self();
        $record->transaction_id     = $transactionId;
        $record->event_type         = $eventType;
        $record->payload            = $jsonPayload; // encrypted cast handles AES-256-CBC
        $record->payload_hmac       = $hmac;
        $record->authorization_code = $authorizationCode;
        $record->client_id          = $clientId;
        $record->jti                = $jti;
        $record->iss                = $iss;
        $record->sub                = $sub;
        $record->iat                = $iat;
        $record->exp                = $exp;
        $record->ip_address         = $ipAddress;
        $record->user_agent         = $userAgent;
        $record->save();

        return $record;
    }

    public function verifyIntegrity(): bool
    {
        $jsonPayload = $this->payload; // decrypted by the encrypted cast
        $expected = hash_hmac('sha256', $jsonPayload, config('app.key'));

        return hash_equals($expected, $this->payload_hmac);
    }

    public function scopeForTransaction(Builder $query, string $transactionId): Builder
    {
        return $query->where('transaction_id', $transactionId);
    }

    public function scopeExpired(Builder $query, int $months = 24): Builder
    {
        return $query->where('created_at', '<', now()->subMonths($months));
    }
}
