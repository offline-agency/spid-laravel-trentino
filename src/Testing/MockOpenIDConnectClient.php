<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Testing;

use Illuminate\Http\RedirectResponse;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

/**
 * Drop-in OIDC client for application tests: no HTTP, fixed tokens and an
 * AAC-shaped userinfo payload.
 *
 *     $this->app->instance(LaravelOpenIDConnectClient::class, new MockOpenIDConnectClient);
 */
class MockOpenIDConnectClient extends LaravelOpenIDConnectClient
{
    public const string ACCESS_TOKEN = 'mock-access-token';

    public const string REFRESH_TOKEN = 'mock-refresh-token';

    public const string ID_TOKEN = 'mock-id-token';

    public const string FISCAL_CODE = 'TINIT-RSSMRA80A01H501U';

    /** @var array<string, mixed> */
    private array $userInfo = [
        'sub' => 'mock-subject',
        'given_name' => 'Mario',
        'family_name' => 'Rossi',
        'email' => 'mario.rossi@example.com',
        'preferred_username' => 'mario.rossi',
        'locale' => 'it',
        'zoneinfo' => 'Europe/Rome',
        'realm' => 'mock-realm',
        'id' => 'mock-id',
        'enti-codicefiscale' => ['fiscalCode' => self::FISCAL_CODE, 'id' => 'mock-id'],
        'enti-spid' => ['isSpid' => 'true', 'spidCode' => 'MOCK0000000001', 'id' => 'mock-id'],
        'enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'mock-id'],
        'enti-issuersource' => ['issuerSource' => 'https://idp.mock.invalid', 'id' => 'mock-id'],
    ];

    public function __construct()
    {
        parent::__construct('https://aac.mock.invalid', 'mock-client-id', 'mock-client-secret', 0);
    }

    /**
     * @param  array<string, mixed>  $claims  replaces top-level userinfo claims
     */
    public function withUserInfo(array $claims): static
    {
        $this->userInfo = array_replace($this->userInfo, $claims);

        return $this;
    }

    public function authenticateWith(array $parameters): bool
    {
        $this->setAccessToken(self::ACCESS_TOKEN);

        return true;
    }

    public function authorizationRedirect(): RedirectResponse
    {
        return new RedirectResponse('https://aac.mock.invalid/authorize');
    }

    public function getRefreshToken(): string
    {
        return self::REFRESH_TOKEN;
    }

    public function getIdToken(): string
    {
        return self::ID_TOKEN;
    }

    public function getTokenResponse(): object
    {
        return (object) [
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
            'id_token' => self::ID_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function refreshToken(string $refresh_token): object
    {
        return (object) [
            'access_token' => 'mock-refreshed-access-token',
            'refresh_token' => $refresh_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function requestUserInfo(?string $attribute = null): mixed
    {
        return $attribute === null ? (object) $this->userInfo : ($this->userInfo[$attribute] ?? null);
    }
}
