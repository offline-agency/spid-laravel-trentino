<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use OfflineAgency\SpidLaravelTrentino\Support\TokenResponse;
use stdClass;

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
     * Completes the authorization code flow and stores tokens and user in the
     * session. The ID token signature and claims are verified by the OIDC
     * client inside authenticate().
     *
     * @throws OpenIDConnectClientException
     */
    public function handleCallback(): SpidTrentinoUser
    {
        Log::info('[SPID] Handling AAC callback');

        // Logged before authenticate(), which consumes the state, so failed
        // callbacks show whether the session still held the login round trip.
        Log::info('[SPID] Callback diagnostic', [
            'session_id_hash' => LogRedactor::hash(Session::getId()),
            'has_state' => Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_state'),
            'has_code_verifier' => Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier'),
            'request_has_code' => Request::has('code'),
            'request_has_state' => Request::has('state'),
        ]);

        $this->oidc->authenticate();
        $tokens = TokenResponse::from($this->oidc->getTokenResponse());
        $userInfo = $this->oidc->requestUserInfo();
        $user = new SpidTrentinoUser($userInfo instanceof stdClass ? $userInfo : []);

        if ($user->getFiscalNumber() === '') {
            throw new OpenIDConnectClientException('AAC did not return a fiscal code for the authenticated user.');
        }

        $this->storeTokens($tokens);
        Session::put(SessionKeys::USER, $user->toArray());

        Log::info('[SPID] User authenticated by AAC', LogRedactor::user($user));
        Event::dispatch(new SpidTrentinoLoggedIn($user));

        return $user;
    }

    /**
     * @throws OpenIDConnectClientException when AAC refuses the refresh or cannot be reached
     */
    public function refreshAccessToken(): void
    {
        $refreshToken = Session::get(SessionKeys::REFRESH_TOKEN);

        if (! is_string($refreshToken) || $refreshToken === '') {
            Log::warning('[SPID] No refresh token in session');

            return;
        }

        $tokens = TokenResponse::from($this->oidc->refreshToken($refreshToken));

        if ($tokens->accessToken === null) {
            throw new OpenIDConnectClientException('AAC refused the token refresh: '.($tokens->error ?? 'no access token returned'));
        }

        $this->storeTokens($tokens, $refreshToken);
        Log::info('[SPID] Access token refreshed');
    }

    /**
     * Logs the user out of the application, ends the session and dispatches
     * SpidTrentinoLoggedOut. The SPID user is read before the session ends.
     */
    public function logout(): void
    {
        $payload = Session::get(SessionKeys::USER);
        $user = new SpidTrentinoUser(is_array($payload) ? $payload : []);

        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        if ($user->getFiscalNumber() !== '') {
            Log::info('[SPID] User logged out', LogRedactor::user($user));
            Event::dispatch(new SpidTrentinoLoggedOut($user));
        }
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function getUserInfo(): ?object
    {
        $accessToken = Session::get(SessionKeys::ACCESS_TOKEN);

        if (is_string($accessToken)) {
            $this->oidc->setAccessToken($accessToken);
        }

        $userInfo = $this->oidc->requestUserInfo();

        return is_object($userInfo) ? $userInfo : null;
    }

    private function storeTokens(TokenResponse $tokens, ?string $currentRefreshToken = null): void
    {
        Session::put(SessionKeys::ACCESS_TOKEN, $tokens->accessToken);

        $refreshToken = $tokens->refreshToken ?? $currentRefreshToken;

        if ($refreshToken === null) {
            Session::forget(SessionKeys::REFRESH_TOKEN);
        } else {
            Session::put(SessionKeys::REFRESH_TOKEN, $refreshToken);
        }

        SessionExpiry::put($tokens->expiresAt());
    }
}
