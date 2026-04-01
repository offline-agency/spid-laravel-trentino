<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClient;

/**
 * Subclass of OpenIDConnectClient that stores OIDC session data (state, nonce,
 * code_verifier) in Laravel's session instead of PHP native $_SESSION.
 *
 * When the session cookie is lost (e.g. corporate proxy stripping cookies),
 * a cache-based fallback keyed by the OAuth `state` parameter (which travels
 * in the URL, not in cookies) is used to recover the PKCE data.
 */
class LaravelSessionOpenIDConnectClient extends OpenIDConnectClient
{
    /** @var array<string, mixed> In-memory buffer of all values written via setSessionKey() */
    private array $pendingSessionData = [];

    protected function startSession(): void
    {
    }

    protected function commitSession(): void
    {
        Session::save();

        $state = $this->pendingSessionData['openid_connect_state'] ?? null;
        if ($state) {
            Cache::put("oidc:{$state}", $this->pendingSessionData, now()->addMinutes(10));
        }
    }

    protected function getSessionKey($key): mixed
    {
        $value = Session::get($key, false);
        if ($value !== false) {
            return $value;
        }

        if (isset($_REQUEST['state'])) {
            $cached = Cache::get("oidc:{$_REQUEST['state']}");
            if (is_array($cached) && isset($cached[$key])) {
                Log::info('[SPID] Cache fallback used', ['key' => $key]);

                return $cached[$key];
            }
        }

        return false;
    }

    protected function setSessionKey($key, $value): void
    {
        Session::put($key, $value);
        $this->pendingSessionData[$key] = $value;
    }

    protected function unsetSessionKey($key): void
    {
        Session::forget($key);
        unset($this->pendingSessionData[$key]);

        if ($key === 'openid_connect_state' && isset($_REQUEST['state'])) {
            Cache::forget("oidc:{$_REQUEST['state']}");
        }
    }
}
