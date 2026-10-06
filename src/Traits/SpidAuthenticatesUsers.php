<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

trait SpidAuthenticatesUsers
{
    /* -------------------------------------------------------- *
     *  LOGIN da oggetto SpidTrentinoUser                        *
     * -------------------------------------------------------- */
    /**
     * Authenticate (or create) a user from the SPID payload.
     */
    protected function authenticateFromSpid(SpidTrentinoUser $spidUser): void
    {
        /** 1. Resolve the configured User model class */
        $userModel = config('auth.providers.users.model');

        /** 2. Find the user by fiscal code or create a new instance */
        /** @var Model|Authenticatable $user */
        $user = $userModel::firstOrNew(['fiscal_code' => $spidUser->getFiscalNumber()]);

        /** 3. Sync profile data (updated every login or set on first creation) */
        $user->fill([
            'name' => $spidUser->getName(),
            'surname' => $spidUser->getSurname(),
            'preferred_username' => $spidUser->getPreferredUsername(),
            'locale' => $spidUser->getLocale(),
            'zoneinfo' => $spidUser->getZoneInfo(),
        ]);

        /** 4. Store the full SPID payload */
        $user->spid_profile = $spidUser->toArray();

        /** 5. Persist if it’s a new record or if any field changed */
        $user->save();

        /** 6. Log the user in and regenerate the session ID */
        Auth::login($user);
        Session::regenerate();

        Log::debug('[SPID] user authenticated', ['user_id' => $user->id]);
    }

    /**
     * Logs the failure and sends the user to error_redirect_to with a flash message.
     */
    protected function spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse
    {
        Log::error('[SPID] Authentication failed', ['exception' => $exception]);

        return Redirect::to(Config::string('spid-laravel-trentino.error_redirect_to'))
            ->with(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
    }

    /* -------------------------------------------------------- *
     *  Redirect post-login                                     *
     * -------------------------------------------------------- */
    protected function redirectTo(): string
    {
        $configured = Config::get('spid-laravel-trentino.redirect_to');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $user = Auth::user();

        if ($user !== null && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return '/admin/dashboard';
        }

        return '/';
    }
}
