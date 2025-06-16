<?php
declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
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
    $spidTrentino = new SpidTrentino();
    $spidTrentino->handleCallback();

    $sessionUser = Session::get('spid_trentino_user');
    $spidUser = new SpidTrentinoUser((array) $sessionUser);

    $this->authenticateFromSpid($spidUser);

    return redirect()->intended($this->redirectTo());
  }

  /* ---------------- logout locale --------------- */
  public function logout(): RedirectResponse
  {
    $this->spidLogout();
    return redirect('/login');
  }
}
