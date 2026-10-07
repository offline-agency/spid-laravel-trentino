<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Services\SpidTransactionLogger;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use OfflineAgency\SpidLaravelTrentino\Support\TokenResponse;
use stdClass;
use Throwable;

class SpidTrentino
{
    /** Cache key holding the unix time of the last successful SPID login. */
    public const string LAST_LOGIN_CACHE_KEY = 'spid-laravel-trentino:last-login';

    public function __construct(
        private readonly LaravelOpenIDConnectClient $oidc,
        private readonly SpidTransactionLogger $transactionLog,
    ) {}

    /**
     * @throws OpenIDConnectClientException
     */
    public function redirectToLogin(): RedirectResponse
    {
        Log::info('[SPID] Redirecting to AAC Trentino login');

        $redirect = $this->oidc->authorizationRedirect();

        $transactionId = SpidTransactionLogger::newTransactionId();
        Session::put(SessionKeys::TRANSACTION_ID, $transactionId);

        $target = $redirect->getTargetUrl();
        parse_str((string) parse_url($target, PHP_URL_QUERY), $request);
        $this->transactionLog->logAuthenticationRequest($transactionId, [
            'authorization_endpoint' => strtok($target, '?'),
            ...$request,
        ]);

        return $redirect;
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

        $transactionId = $this->transactionId();
        $callback = Request::only(['code', 'state', 'error', 'error_description']);
        $this->transactionLog->logAuthenticationResponse($transactionId, $callback);

        $this->oidc->authenticate();

        $this->transactionLog->logTokenRequest($transactionId, [
            'grant_type' => 'authorization_code',
            'code' => $callback['code'] ?? null,
            'client_id' => $this->oidc->getClientID(),
            'redirect_uri' => $this->oidc->getRedirectURL(),
        ]);
        $rawTokens = $this->oidc->getTokenResponse();
        $this->transactionLog->logTokenResponse($transactionId, SpidTransactionLogger::redactTokens(self::toArray($rawTokens)));
        $tokens = TokenResponse::from($rawTokens);

        $this->transactionLog->logUserInfoRequest($transactionId, ['authorization' => 'Bearer (access token)']);
        $userInfo = $this->oidc->requestUserInfo();
        $this->transactionLog->logUserInfoResponse($transactionId, self::toArray($userInfo));
        $user = new SpidTrentinoUser($userInfo instanceof stdClass ? $userInfo : []);

        if ($user->getFiscalNumber() === '') {
            throw new OpenIDConnectClientException('AAC did not return a fiscal code for the authenticated user.');
        }

        $this->storeTokens($tokens);
        Session::put(SessionKeys::USER, $user->toArray());
        $this->rememberLogin();

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

        $transactionId = $this->transactionId();
        $this->transactionLog->logRefreshRequest($transactionId, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->oidc->getClientID(),
        ]);
        $rawTokens = $this->oidc->refreshToken($refreshToken);
        $this->transactionLog->logRefreshResponse($transactionId, SpidTransactionLogger::redactTokens(self::toArray($rawTokens)));
        $tokens = TokenResponse::from($rawTokens);

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

        if ($user->getFiscalNumber() !== '') {
            $this->transactionLog->logLogout($this->transactionId(), ['sub' => $user->getSub()]);
        }

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

    /**
     * Transaction log id of the current login, or a new one when the session has none.
     */
    /**
     * Records the time of this login for spid:check-logs, in the cache so it
     * survives a broken log table. Best effort: never fails the login.
     */
    private function rememberLogin(): void
    {
        try {
            Cache::forever(self::LAST_LOGIN_CACHE_KEY, CarbonImmutable::now()->getTimestamp());
        } catch (Throwable $exception) {
            Log::warning('[SPID] Could not record the last login time', ['exception' => $exception::class]);
        }
    }

    private function transactionId(): string
    {
        $transactionId = Session::get(SessionKeys::TRANSACTION_ID);

        return is_string($transactionId) ? $transactionId : SpidTransactionLogger::newTransactionId();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value): array
    {
        $array = json_decode((string) json_encode($value), true);

        return is_array($array) ? $array : [];
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
