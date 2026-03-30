<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClient;

/**
 * Subclass of OpenIDConnectClient that stores OIDC session data (state, nonce,
 * code_verifier) in Laravel's session instead of PHP native $_SESSION.
 *
 * This eliminates the dependency on the PHPSESSID cookie, which can be dropped
 * by corporate proxies on cross-site redirects, causing PKCE verification to fail.
 */
class LaravelSessionOpenIDConnectClient extends OpenIDConnectClient
{
    protected function startSession(): void
    {
        // Laravel manages its own session — no native session_start() needed.
    }

    protected function commitSession(): void
    {
        // Laravel commits its session at the end of the request lifecycle.
    }

    protected function getSessionKey($key): mixed
    {
        return Session::get($key, false);
    }

    protected function setSessionKey($key, $value): void
    {
        Session::put($key, $value);
    }

    protected function unsetSessionKey($key): void
    {
        Session::forget($key);
    }
}
