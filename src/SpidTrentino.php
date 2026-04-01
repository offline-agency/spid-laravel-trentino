<?php

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class SpidTrentino
{
  protected OpenIDConnectClient $oidc;

  public function __construct()
  {
    $this->oidc = new OpenIDConnectClient(
      config('spid-laravel-trentino.provider_url'),
      config('spid-laravel-trentino.client_id'),
      config('spid-laravel-trentino.client_secret')
    );

    try {
      Log::info('[SPID] SpidTrentino initialized', [
        'provider_url'     => config('spid-laravel-trentino.provider_url'),
        'client_id_set'    => ! empty(config('spid-laravel-trentino.client_id')),
        'client_secret_set' => ! empty(config('spid-laravel-trentino.client_secret')),
        'redirect_uri'     => config('spid-laravel-trentino.redirect_uri'),
        'session_id_hash'  => hash('sha256', (string) Session::getId()),
      ]);
    } catch (\Throwable $e) {
      // silent
    }

    $this->oidc->setRedirectURL(config('spid-laravel-trentino.redirect_uri'));
    $this->oidc->addScope(config('spid-laravel-trentino.scopes'));
    $this->oidc->setCodeChallengeMethod('S256');
  }

  public function redirectToLogin(): bool
  {
    try {
      Log::info('[SPID] Redirecting to AAC Trentino login', [
        'session_id_hash' => hash('sha256', (string) Session::getId()),
        'timestamp'       => now()->toIso8601String(),
      ]);
    } catch (\Throwable $e) {
      // silent
    }
    return $this->oidc->authenticate();
  }

  /**
   * @throws OpenIDConnectClientException
   */
  public function handleCallback(): void
  {
    $this->logCallbackDiagnostics();
    Log::info('[SPID] Handling AAC callback');
    $this->oidc->authenticate();

    $accessToken  = $this->oidc->getAccessToken();
    $refreshToken = $this->oidc->getRefreshToken();
    $expiresAt    = $this->extractExpiry();
    $userInfo     = $this->oidc->requestUserInfo();

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

    $accessToken  = $this->oidc->getAccessToken();
    $newRefresh   = $this->oidc->getRefreshToken() ?: $refreshToken;
    $expiresAt    = $this->extractExpiry();

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

  protected function logCallbackDiagnostics(): void
  {
    $hasState = false;
    $hasCodeVerifier = false;

    try {
      $reflection = new \ReflectionClass($this->oidc);
      if ($reflection->hasMethod('getState')) {
        $method = $reflection->getMethod('getState');
        $method->setAccessible(true);
        $state = $method->invoke($this->oidc);
        $hasState = ! empty($state);
      }
      if ($reflection->hasMethod('getCodeVerifier')) {
        $method = $reflection->getMethod('getCodeVerifier');
        $method->setAccessible(true);
        $codeVerifier = $method->invoke($this->oidc);
        $hasCodeVerifier = ! empty($codeVerifier);
      }
    } catch (\Throwable $e) {
      Log::warning('[SPID] Could not read session diagnostics: ' . $e->getMessage());
    }

    try {
      Log::info('[SPID] Callback diagnostic', [
        'session_id_hash'      => hash('sha256', (string) Session::getId()),
        'has_state'            => $hasState,
        'has_code_verifier'    => $hasCodeVerifier,
        'request_has_code'     => isset($_REQUEST['code']),
        'request_has_state'    => isset($_REQUEST['state']),
        'timestamp'            => now()->toIso8601String(),
      ]);
    } catch (\Throwable $e) {
      // silent
    }
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
    string  $accessToken,
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
    $jwksUri = rtrim(config('spid-laravel-trentino.provider_url'), '/') . '/.well-known/jwks.json';
    $jwks = Http::get($jwksUri)->json();

    if (! isset($jwks['keys'])) {
      Log::error('[SPID] Unable to fetch JWKS from AAC');
      throw new \UnexpectedValueException('Invalid JWKS response');
    }

    try {
      $decoded = JWT::decode($idToken, JWK::parseKeySet($jwks));
      Log::debug('[SPID] ID token successfully validated', (array) $decoded);
    } catch (\Throwable $e) {
      Log::error('[SPID] ID token validation failed: ' . $e->getMessage());
      throw new \UnexpectedValueException('Invalid ID token: ' . $e->getMessage(), 0, $e);
    }
  }
}
