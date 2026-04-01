<?php
declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

class SpidAuthController extends Controller
{
  use SpidAuthenticatesUsers;

  /* ---------------- callback AAC ---------------- */
  /**
   * @throws OpenIDConnectClientException
   */
  public function callback(): RedirectResponse
  {
    try {
      $spidTrentino = new SpidTrentino();
      $spidTrentino->handleCallback();

      $sessionUser = Session::get('spid_trentino_user');
      $spidUser = new SpidTrentinoUser((array) $sessionUser);

      Log::info('SpidAuthController:callback', ['spidUser' => $spidUser]);
      $this->authenticateFromSpid($spidUser);
      Log::debug('[SPID] User authenticated - callback', ['user_id' => Auth::id()]);

      Log::debug('Redirect to' . $this->redirectTo());
      return redirect()->intended($this->redirectTo());
    } catch (OpenIDConnectClientException $e) {
      try {
        Log::error('[SPID] Token exchange failed', [
          'message'                => $e->getMessage(),
          'session_id_hash'        => hash('sha256', (string) Session::getId()),
          'has_oidc_state'         => isset($_SESSION['openid_connect_state']),
          'has_oidc_code_verifier' => isset($_SESSION['openid_connect_code_verifier']),
          'timestamp'              => now()->toIso8601String(),
        ]);
      } catch (\Throwable $logEx) {
        // silent
      }
      throw $e;
    }
  }

  /* ---------------- logout locale --------------- */
  public function logout(): RedirectResponse
  {
    $this->spidLogout();
    return redirect('/login');
  }
}
