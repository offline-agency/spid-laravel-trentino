<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OpenSSLAsymmetricKey;

/**
 * Stand-in for AAC Trentino: discovery, JWKS, token and userinfo endpoints
 * served through Http::fake(), with real RS256-signed ID tokens.
 */
final class FakeAacProvider
{
    public const string ISSUER = 'https://aac.test';

    public const string CLIENT_ID = 'test-client';

    public const string CLIENT_SECRET = 'test-secret';

    public const string KEY_ID = 'test-key';

    public const string STATE = 'state-123';

    public const string NONCE = 'nonce-123';

    public const string CODE_VERIFIER = 'verifier-123';

    public const string ACCESS_TOKEN = 'test-access-token-value';

    public const string REFRESH_TOKEN = 'test-refresh-token-value';

    public const string FISCAL_CODE = 'TINIT-RSSMRA80A01H501U';

    public const string NORMALIZED_FISCAL_CODE = 'RSSMRA80A01H501U';

    /** @var array<string, OpenSSLAsymmetricKey> */
    private static array $keys = [];

    public static function discoveryUrl(): string
    {
        return self::ISSUER.'/.well-known/openid-configuration';
    }

    /** @return array<string, mixed> */
    public static function discovery(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwk',
            'end_session_endpoint' => self::ISSUER.'/endsession',
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
    }

    /** @return array{keys: list<array<string, string>>} */
    public static function jwks(string $key = 'trusted'): array
    {
        $details = openssl_pkey_get_details(self::key($key));

        return ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];
    }

    /**
     * @param  array<string, mixed>  $overrides  claims to change; null removes a claim
     */
    public static function idToken(array $overrides = [], string $signingKey = 'trusted'): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-123',
            'iat' => time(),
            'exp' => time() + 600,
            'nonce' => self::NONCE,
        ], $overrides);

        openssl_pkey_export(self::key($signingKey), $pem);

        return JWT::encode(array_filter($claims, fn (mixed $value): bool => $value !== null), $pem, 'RS256', self::KEY_ID);
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes a field
     * @return array<string, mixed>
     */
    public static function tokenResponse(array $overrides = []): array
    {
        return array_filter(array_merge([
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => self::idToken(),
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * Userinfo shaped like AAC Trentino's (enti-* claims).
     *
     * @param  array<string, mixed>  $overrides  null removes a claim
     * @return array<string, mixed>
     */
    public static function userInfo(array $overrides = []): array
    {
        return array_filter(array_merge([
            'sub' => 'subject-123',
            'given_name' => 'Mario',
            'family_name' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'preferred_username' => 'mario.rossi',
            'locale' => 'it',
            'zoneinfo' => 'Europe/Rome',
            'realm' => 'test-realm',
            'id' => 'user-123',
            'enti-codicefiscale' => ['fiscalCode' => self::FISCAL_CODE, 'id' => 'cf-1'],
            'enti-spid' => ['isSpid' => 'true', 'spidCode' => 'TEST0000000001', 'id' => 'spid-1'],
            'enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'acr-1'],
            'enti-issuersource' => ['issuerSource' => 'https://idp.test', 'id' => 'issuer-1'],
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $token  token endpoint overrides (see tokenResponse())
     * @param  array<string, mixed>  $userInfo  userinfo overrides (see userInfo())
     */
    public static function fake(array $token = [], array $userInfo = []): void
    {
        Http::fake([
            self::discoveryUrl() => Http::response(self::discovery()),
            self::ISSUER.'/jwk' => Http::response(self::jwks()),
            self::ISSUER.'/oauth/token' => Http::response(self::tokenResponse($token)),
            self::ISSUER.'/userinfo*' => Http::response(self::userInfo($userInfo)),
        ]);
    }

    /**
     * Puts the session in the state the login route leaves it in.
     */
    public static function startAuthorization(): void
    {
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_state', self::STATE);
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_nonce', self::NONCE);
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier', self::CODE_VERIFIER);
    }

    private static function key(string $name): OpenSSLAsymmetricKey
    {
        return self::$keys[$name] ??= openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
