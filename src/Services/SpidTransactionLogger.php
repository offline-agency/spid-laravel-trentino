<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use OfflineAgency\SpidLaravelTrentino\Exceptions\TransactionLogUnavailable;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;
use Throwable;

/**
 * Writes the OIDC messages of each SPID login to the transaction log required
 * by the SPID/CIE OIDC retention policy. A write failure is logged (without
 * the payload) and, by default, ignored. With transaction_log.fail_closed it
 * aborts the login or refresh instead; logout is never blocked.
 */
class SpidTransactionLogger
{
    /** Cache key holding the unix time of the last failed write, read by spid:check-logs. */
    public const string LAST_WRITE_FAILURE_CACHE_KEY = 'spid-laravel-trentino:last-write-failure';

    /** Token response fields replaced by their SHA-256: the spec does not require the raw tokens. */
    private const array REDACTED_TOKENS = ['access_token', 'refresh_token'];

    public static function newTransactionId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Removes the client secret and replaces access and refresh tokens with
     * `sha256:<hash>`, so records can be correlated without storing usable
     * credentials. The signed id_token is kept as evidence.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<array-key, mixed>
     */
    public static function redactTokens(array $response): array
    {
        unset($response['client_secret']);

        foreach (self::REDACTED_TOKENS as $field) {
            if (is_string($response[$field] ?? null)) {
                $response[$field] = 'sha256:'.hash('sha256', $response[$field]);
            }
        }

        return $response;
    }

    /** @param array<array-key, mixed> $data */
    public function logAuthenticationRequest(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'authentication_request', $data);
    }

    /** @param array<array-key, mixed> $data */
    public function logAuthenticationResponse(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'authentication_response', $data, [
            'authorizationCode' => self::string($data, 'code'),
        ]);
    }

    /** @param array<array-key, mixed> $data */
    public function logTokenRequest(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'token_request', $data, [
            'authorizationCode' => self::string($data, 'code'),
        ]);
    }

    /** @param array<array-key, mixed> $data token response, already redacted */
    public function logTokenResponse(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'token_response', $data, $this->idTokenFields($data));
    }

    /** @param array<array-key, mixed> $data */
    public function logUserInfoRequest(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'userinfo_request', $data);
    }

    /** @param array<array-key, mixed> $data */
    public function logUserInfoResponse(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'userinfo_response', $data, [
            'sub' => self::string($data, 'sub'),
            'iss' => self::string($data, 'iss'),
        ]);
    }

    /** @param array<array-key, mixed> $data */
    public function logRefreshRequest(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'refresh_request', $data);
    }

    /** @param array<array-key, mixed> $data token response, already redacted */
    public function logRefreshResponse(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'refresh_response', $data, $this->idTokenFields($data));
    }

    /** @param array<array-key, mixed> $data */
    public function logLogout(string $transactionId, array $data): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'logout', $data, [
            'sub' => self::string($data, 'sub'),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array{authorizationCode?: ?string, jti?: ?string, iss?: ?string, sub?: ?string, iat?: ?CarbonImmutable, exp?: ?CarbonImmutable}  $fields
     *
     * @throws TransactionLogUnavailable when the write fails and fail_closed is on (never for logout)
     */
    private function write(string $transactionId, string $eventType, array $payload, array $fields = []): ?SpidTransactionLog
    {
        if (! filter_var(Config::get('spid-laravel-trentino.transaction_log.enabled', true), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $clientId = Config::get('spid-laravel-trentino.client_id');

        try {
            return SpidTransactionLog::log(
                transactionId: $transactionId,
                eventType: $eventType,
                payload: $payload,
                authorizationCode: $fields['authorizationCode'] ?? null,
                clientId: is_string($clientId) ? $clientId : null,
                jti: $fields['jti'] ?? null,
                iss: $fields['iss'] ?? null,
                sub: $fields['sub'] ?? null,
                iat: $fields['iat'] ?? null,
                exp: $fields['exp'] ?? null,
                ipAddress: Request::ip(),
                userAgent: Request::userAgent(),
            );
        } catch (Throwable $exception) {
            // The message is not logged: database errors include the bound values.
            Log::error('[SPID] Transaction log write failed', [
                'event_type' => $eventType,
                'transaction_id' => $transactionId,
                'exception' => $exception::class,
            ]);

            try {
                Cache::forever(self::LAST_WRITE_FAILURE_CACHE_KEY, CarbonImmutable::now()->getTimestamp());
            } catch (Throwable) {
                // Best effort: the failure itself is already in the error log.
            }

            if ($eventType !== 'logout' && filter_var(Config::get('spid-laravel-trentino.transaction_log.fail_closed', false), FILTER_VALIDATE_BOOL)) {
                throw new TransactionLogUnavailable($eventType);
            }

            return null;
        }
    }

    /**
     * Search fields taken from the (already verified) ID token of a token response.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{jti: ?string, iss: ?string, sub: ?string, iat: ?CarbonImmutable, exp: ?CarbonImmutable}
     */
    private function idTokenFields(array $data): array
    {
        $claims = self::jwtClaims($data['id_token'] ?? null);

        return [
            'jti' => self::string($claims, 'jti'),
            'iss' => self::string($claims, 'iss'),
            'sub' => self::string($claims, 'sub'),
            'iat' => is_int($claims['iat'] ?? null) ? CarbonImmutable::createFromTimestamp($claims['iat']) : null,
            'exp' => is_int($claims['exp'] ?? null) ? CarbonImmutable::createFromTimestamp($claims['exp']) : null,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function jwtClaims(mixed $token): array
    {
        $parts = is_string($token) ? explode('.', $token) : [];

        if (count($parts) !== 3) {
            return [];
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        return is_array($claims) ? $claims : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
