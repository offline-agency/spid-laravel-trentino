<?php

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Contracts\Container\BindingResolutionException;
use Jumbojett\OpenIDConnectClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;


class SpidTrentino
{
  protected $oidc;

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
   * @throws OpenIDConnectClientException
   * @throws BindingResolutionException
   */
  public function handleCallback(): void
  {
    Log::info('Handling AAC Trentino callback.');
    $this->oidc->authenticate();

    $accessToken = $this->oidc->getAccessToken();
    $refreshToken = $this->oidc->getRefreshToken();
    $idToken = $this->oidc->getIdToken();
    $userInfo = $this->oidc->requestUserInfo();

    Log::debug('User info received from AAC Trentino:', (array) $userInfo);

    Session::put('access_token', $accessToken);
    Session::put('refresh_token', $refreshToken);

    // Replace this block with your actual user resolution/creation logic.
    $user = app()->make('user.resolver')->resolveOrCreate((array) $userInfo);

    Log::info('User logged in via AAC Trentino', ['user_id' => $user->id]);
    Auth::login($user);

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
   * Also dispatches the AacTrentinoLogout event.
   *
   * @return void
   */
  public function logout(): void
  {
    Log::info('Logging out user.');

    $user = Auth::user();

    Session::flush();
    Auth::logout();

    Log::info('Session flushed and user logged out.');

    if ($user) {
      Log::info('Dispatching AacTrentinoLogout event.', ['user_id' => $user->id]);
      Event::dispatch(new SpidTrentinoLoggedOut($user));
    }
  }

  /**
   * @throws OpenIDConnectClientException
   */
  public function getUserInfo()
  {
    Log::info('Fetching user info from AAC Trentino.');
    return $this->oidc->requestUserInfo();
  }
}
