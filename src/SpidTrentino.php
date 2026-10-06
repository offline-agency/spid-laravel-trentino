<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

class SpidTrentino
{
    public function __construct(private readonly LaravelOpenIDConnectClient $oidc) {}

    /**
     * @throws OpenIDConnectClientException
     */
    public function redirectToLogin(): RedirectResponse
    {
        Log::info('[SPID] Redirecting to AAC Trentino login');

        return $this->oidc->authorizationRedirect();
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function handleCallback(): void
    {
        Log::info('[SPID] Handling AAC callback');
        $this->oidc->authenticate();

        $accessToken = $this->oidc->getAccessToken();
        $refreshToken = $this->oidc->getRefreshToken();
        $expiresAt = $this->extractExpiry();
        $userInfo = $this->oidc->requestUserInfo();

        // Validate ID token, if available
        $this->validateIdToken();

        $this->storeTokensInSession($accessToken, $refreshToken, $expiresAt);

        $user = new SpidTrentinoUser((array) $userInfo);
        Session::put('spid_trentino_user', $user->toArray());
        Session::put('refresh_token', $refreshToken);
        Session::put('access_token_expires_at', $expiresAt);
        Log::debug('[SPID] User info stored in session', (array) $user);
        Log::debug('[SPID] Refresh token stored in session', ['refresh_token' => $refreshToken]);
        Log::debug('[SPID] Access token expires at', ['expires_at' => $expiresAt]);
        Log::debug('[SPID] Access token stored in session', ['access_token' => $accessToken]);

        Event::dispatch(new SpidTrentinoLoggedIn($user));
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function refreshAccessToken(): void
    {
        Log::info('[SPID] Refreshing access token');

        $refreshToken = Session::get('refresh_token');
        if (! $refreshToken) {
            Log::warning('[SPID] No refresh token in session');

            return;
        }

        $this->oidc->refreshToken($refreshToken);

        $accessToken = $this->oidc->getAccessToken();
        $newRefresh = $this->oidc->getRefreshToken() ?: $refreshToken;
        $expiresAt = $this->extractExpiry();

        $this->storeTokensInSession($accessToken, $newRefresh, $expiresAt);
    }

    public function logout(): void
    {
        $user = new SpidTrentinoUser(Session::get('spid_trentino_user', []));

        Session::flush();
        Auth::logout();

        if ($user->fiscalNumber) {
            Event::dispatch(new SpidTrentinoLoggedOut($user));
        }
    }

    public function getUserInfo(): ?object
    {
        return $this->oidc->requestUserInfo();
    }

    protected function extractExpiry(): ?Carbon
    {
        if (! method_exists($this->oidc, 'getTokenResponse')) {
            return null;
        }

        $tokenResponse = $this->oidc->getTokenResponse();
        if (is_array($tokenResponse) && isset($tokenResponse['expires_in'])) {
            return Carbon::now()->addSeconds((int) $tokenResponse['expires_in']);
        }

        return null;
    }

    protected function storeTokensInSession(
        string $accessToken,
        ?string $refreshToken,
        ?Carbon $expiresAt
    ): void {
        Session::put('access_token', $accessToken);

        if ($refreshToken !== null) {
            Session::put('refresh_token', $refreshToken);
        }

        if ($expiresAt !== null) {
            Session::put('access_token_expires_at', $expiresAt);
        } else {
            Session::forget('access_token_expires_at');
        }
    }

    /**
     * Validates the ID token returned by AAC (if present).
     *
     * @throws \UnexpectedValueException if the token is invalid
     */
    protected function validateIdToken(): void
    {
        if (! method_exists($this->oidc, 'getTokenResponse')) {
            return;
        }

        $response = $this->oidc->getTokenResponse();
        if (! is_array($response) || ! isset($response['id_token'])) {
            Log::warning('[SPID] No ID token found to validate');

            return;
        }

        $idToken = $response['id_token'];

        // Discover JWKS URI from provider base URL
        $jwksUri = rtrim(config('spid-laravel-trentino.provider_url'), '/').'/.well-known/jwks.json';
        $jwks = Http::get($jwksUri)->json();

        if (! isset($jwks['keys'])) {
            Log::error('[SPID] Unable to fetch JWKS from AAC');
            throw new \UnexpectedValueException('Invalid JWKS response');
        }

        try {
            $decoded = JWT::decode($idToken, JWK::parseKeySet($jwks));
            Log::debug('[SPID] ID token successfully validated', (array) $decoded);
        } catch (\Throwable $e) {
            Log::error('[SPID] ID token validation failed: '.$e->getMessage());
            throw new \UnexpectedValueException('Invalid ID token: '.$e->getMessage(), 0, $e);
        }
    }
}
