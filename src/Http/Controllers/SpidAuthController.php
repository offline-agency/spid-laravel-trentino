<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redirect;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

class SpidAuthController extends Controller
{
    use SpidAuthenticatesUsers;

    public function login(SpidTrentino $spid): RedirectResponse
    {
        try {
            return $spid->redirectToLogin();
        } catch (OpenIDConnectClientException $exception) {
            return $this->spidLoginFailed($exception);
        }
    }

    public function callback(SpidTrentino $spid): RedirectResponse
    {
        try {
            $spidUser = $spid->handleCallback();
        } catch (OpenIDConnectClientException $exception) {
            return $this->spidLoginFailed($exception);
        }

        $this->authenticateFromSpid($spidUser);

        return Redirect::intended($this->redirectTo());
    }

    public function logout(SpidTrentino $spid): RedirectResponse
    {
        $spid->logout();

        return Redirect::to(Config::string('spid-laravel-trentino.logout_redirect_to'));
    }
}
