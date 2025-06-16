<?php

namespace OfflineAgency\SpidLaravelTrentino\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
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
      'email'              => $spidUser->getEmail(),
      'name'               => $spidUser->getName(),
      'surname'            => $spidUser->getSurname(),
      'preferred_username' => $spidUser->getPreferredUsername(),
      'locale'             => $spidUser->getLocale(),
      'zoneinfo'           => $spidUser->getZoneInfo(),
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


  /* -------------------------------------------------------- *
   *  LOGOUT                                                  *
   * -------------------------------------------------------- */
  protected function spidLogout(): void
  {
    Auth::logout();
    Session::flush();
    Log::debug('[SPID] logout eseguito');
  }

  /* -------------------------------------------------------- *
   *  Redirect post-login                                     *
   * -------------------------------------------------------- */
  protected function redirectTo(): string
  {
    // 1. override da config
    if ($path = config('spid.redirect_to')) {
      return $path;
    }

    // 2. logica custom (es. admin)
    $user = Auth::user();
    if ($user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
      return '/admin/dashboard';
    }

    // 3. fallback
    return '/';
  }
}
