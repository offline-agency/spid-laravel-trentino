<?php

namespace OfflineAgency\SpidLaravelTrentino\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

class SpidTransactionLogger
{
    public static function newTransactionId(): string
    {
        return Str::uuid()->toString();
    }

    public function logAuthenticationRequest(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'authentication_request', $data, $request);
    }

    public function logAuthenticationResponse(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write(
            $transactionId,
            'authentication_response',
            $data,
            $request,
            authorizationCode: $data['code'] ?? null,
        );
    }

    public function logTokenRequest(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'token_request', $data, $request);
    }

    public function logTokenResponse(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        $claims = $this->extractJwtClaims($data['id_token'] ?? null);

        return $this->write(
            $transactionId,
            'token_response',
            $data,
            $request,
            jti: $claims['jti'] ?? null,
            iss: $claims['iss'] ?? null,
            sub: $claims['sub'] ?? ($data['sub'] ?? null),
            iat: isset($claims['iat']) ? Carbon::createFromTimestamp($claims['iat']) : null,
            exp: isset($claims['exp']) ? Carbon::createFromTimestamp($claims['exp']) : null,
        );
    }

    public function logUserInfoRequest(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'userinfo_request', $data, $request);
    }

    public function logUserInfoResponse(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write(
            $transactionId,
            'userinfo_response',
            $data,
            $request,
            sub: $data['sub'] ?? null,
            iss: $data['iss'] ?? null,
        );
    }

    public function logRefreshRequest(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'refresh_request', $data, $request);
    }

    public function logRefreshResponse(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        $claims = $this->extractJwtClaims($data['id_token'] ?? null);

        return $this->write(
            $transactionId,
            'refresh_response',
            $data,
            $request,
            jti: $claims['jti'] ?? null,
            iss: $claims['iss'] ?? null,
            sub: $claims['sub'] ?? ($data['sub'] ?? null),
            iat: isset($claims['iat']) ? Carbon::createFromTimestamp($claims['iat']) : null,
            exp: isset($claims['exp']) ? Carbon::createFromTimestamp($claims['exp']) : null,
        );
    }

    public function logRevocationRequest(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'revocation_request', $data, $request);
    }

    public function logRevocationResponse(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write($transactionId, 'revocation_response', $data, $request);
    }

    public function logLogout(string $transactionId, array $data, ?Request $request = null): ?SpidTransactionLog
    {
        return $this->write(
            $transactionId,
            'logout',
            $data,
            $request,
            sub: $data['sub'] ?? null,
        );
    }

    private function write(
        string $transactionId,
        string $eventType,
        array $payload,
        ?Request $request = null,
        ?string $authorizationCode = null,
        ?string $clientId = null,
        ?string $jti = null,
        ?string $iss = null,
        ?string $sub = null,
        ?\DateTimeInterface $iat = null,
        ?\DateTimeInterface $exp = null,
    ): ?SpidTransactionLog {
        if (! config('spid-laravel-trentino.transaction_log.enabled', true)) {
            return null;
        }

        try {
            $req = $request ?? request();
            $ipAddress = $req?->ip();
            $userAgent = $req?->userAgent();

            return SpidTransactionLog::log(
                transactionId: $transactionId,
                eventType: $eventType,
                payload: $payload,
                authorizationCode: $authorizationCode,
                clientId: $clientId ?? config('spid-laravel-trentino.client_id'),
                jti: $jti,
                iss: $iss,
                sub: $sub,
                iat: $iat,
                exp: $exp,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        } catch (\Throwable $e) {
            Log::error('[SPID] Transaction log write failed', [
                'event_type'     => $eventType,
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractJwtClaims(?string $token): array
    {
        if (! $token) {
            return [];
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        try {
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($decoded === false) {
                return [];
            }

            return (array) json_decode($decoded, true);
        } catch (\Throwable) {
            return [];
        }
    }
}
