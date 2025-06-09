<?php

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;

class SpidTrentino
{
  protected OpenIDConnectClient $oidc;

  public function __construct()
  {
    $this->oidc = new OpenIDConnectClient(
      config('spid-trentino.provider_url'),
      config('spid-trentino.client_id'),
      config('spid-trentino.client_secret')
    );

    $this->oidc->setRedirectURL(config('spid-trentino.redirect_uri'));
    $this->oidc->addScope(config('spid-trentino.scopes'));
    $this->oidc->setCodeChallengeMethod('S256');
  }

  /**
   * Redirects the user to the Trentino AAC login endpoint.
   *
   * @return bool
   * @throws OpenIDConnectClientException
   */
  public function redirectToLogin(): bool
  {
    Log::info('Redirecting to AAC Trentino login.');
    return $this->oidc->authenticate();
  }

  /**
   * Handles the AAC callback and stores user info in session.
   *
   * @return void
   * @throws OpenIDConnectClientException
   */
  public function handleCallback(): void
  {
    Log::info('Handling AAC Trentino callback.');
    $this->oidc->authenticate();

    $accessToken = $this->oidc->getAccessToken();
    $refreshToken = $this->oidc->getRefreshToken();
    $userInfo = $this->oidc->requestUserInfo();

    Log::debug('User info received from AAC Trentino:', (array) $userInfo);

    Session::put('access_token', $accessToken);
    Session::put('refresh_token', $refreshToken);

    $user = new SpidTrentinoUser((array) $userInfo);

    Log::info('User authenticated via AAC Trentino', [
      'fiscal_number' => $user->fiscalNumber,
      'openId' => $user->openId,
      'email' => $user->email
    ]);

    Session::put('spid_trentino_user', $user->toArray());

    Event::dispatch(new SpidTrentinoLoggedIn($user));
  }

  /**
   * Refresh the current access token using the refresh token.
   *
   * @return void
   * @throws OpenIDConnectClientException
   */
  public function refreshAccessToken(): void
  {
    Log::info('Refreshing AAC Trentino access token.');

    $refreshToken = Session::get('refresh_token');
    if (!$refreshToken) {
      Log::warning('No refresh token found in session.');
      return;
    }

    $this->oidc->refreshToken($refreshToken);

    $newAccessToken = $this->oidc->getAccessToken();
    $newRefreshToken = $this->oidc->getRefreshToken();

    Log::debug('New tokens received after refresh.', [
      'access_token' => $newAccessToken,
      'refresh_token' => $newRefreshToken,
    ]);

    Session::put('access_token', $newAccessToken);
    Session::put('refresh_token', $newRefreshToken);
  }

  /**
   * Logs out the current user and clears the session.
   *
   * @return void
   */
  public function logout(): void
  {
    Log::info('Logging out user.');

    $user = new SpidTrentinoUser(Session::get('spid_trentino_user', []));

    Session::flush();
    Auth::logout();

    Log::info('Session flushed and user logged out.');

    if ($user->fiscalNumber) {
      Log::info('Dispatching SpidTrentinoLoggedOut event.', ['fiscal_number' => $user->fiscalNumber]);
      Event::dispatch(new SpidTrentinoLoggedOut($user));
    }
  }

  /**
   * Returns the raw user info from AAC.
   *
   * @return object|null
   * @throws OpenIDConnectClientException
   */
  public function getUserInfo(): ?object
  {
    Log::info('Fetching user info from AAC Trentino.');
    return $this->oidc->requestUserInfo();
  }
}
