<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use LogicException;
use OfflineAgency\SpidLaravelTrentino\Exceptions\AuthenticationRejected;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

trait SpidAuthenticatesUsers
{
    /**
     * Finds the local user by fiscal code (or creates it), syncs the profile
     * and logs it in. The model needs the columns of the package migration,
     * those attributes in $fillable and 'spid_profile' => 'array' in $casts.
     */
    protected function authenticateFromSpid(SpidTrentinoUser $spidUser): void
    {
        $userModel = Config::string('auth.providers.users.model');

        if (! is_a($userModel, Model::class, true)) {
            throw new LogicException("The user model [{$userModel}] must be an Eloquent model.");
        }

        $user = $userModel::query()->firstOrNew(['fiscal_code' => $spidUser->getFiscalNumber()]);

        if (! $user instanceof Authenticatable) {
            throw new LogicException("The user model [{$userModel}] must implement Authenticatable.");
        }

        // Set explicitly: firstOrNew() drops it when the model does not list it in $fillable.
        $user->setAttribute('fiscal_code', $spidUser->getFiscalNumber());

        $user->fill([
            'name' => $spidUser->getName(),
            'surname' => $spidUser->getSurname(),
            'preferred_username' => $spidUser->getPreferredUsername(),
            'locale' => $spidUser->getLocale(),
            'zoneinfo' => $spidUser->getZoneinfo(),
        ]);

        $email = $spidUser->getEmail();

        // Never link or take over another account by email.
        if ($email !== '' && ! $userModel::query()
            ->where('email', $email)
            ->when($user->exists, fn (Builder $query) => $query->whereKeyNot($user->getKey()))
            ->exists()) {
            $user->setAttribute('email', $email);
        }

        $user->setAttribute('spid_profile', $spidUser->toArray());
        $user->save();

        Auth::login($user);
        Session::regenerate();

        Log::info('[SPID] Local user authenticated', ['user_id' => $user->getKey()]);
    }

    /**
     * Logs the failure and sends the user to error_redirect_to with a flash message.
     */
    protected function spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse
    {
        if ($exception instanceof AuthenticationRejected) {
            Log::warning('[SPID] Login rejected', ['reason' => $exception->reason, 'message' => $exception->getMessage()]);

            return Redirect::to(Config::string('spid-laravel-trentino.error_redirect_to'))
                ->with(SessionKeys::ERROR, $exception->userMessage());
        }

        Log::error('[SPID] Authentication failed', ['exception' => $exception]);

        return Redirect::to(Config::string('spid-laravel-trentino.error_redirect_to'))
            ->with(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
    }

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
