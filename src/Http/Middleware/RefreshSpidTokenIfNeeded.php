<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refreshes the access token when it expires within a minute. When the
 * refresh fails the tokens are forgotten, so spid.valid (placed after this
 * middleware) ends the session.
 */
class RefreshSpidTokenIfNeeded
{
    private const int REFRESH_WINDOW_SECONDS = 60;

    public function __construct(private readonly SpidTrentino $spid) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Session::has(SessionKeys::REFRESH_TOKEN) && SessionExpiry::expiresWithin(self::REFRESH_WINDOW_SECONDS)) {
            try {
                $this->spid->refreshAccessToken();
            } catch (OpenIDConnectClientException $exception) {
                Log::warning('[SPID] Access token refresh failed', ['error' => $exception->getMessage()]);

                Session::forget([
                    SessionKeys::ACCESS_TOKEN,
                    SessionKeys::REFRESH_TOKEN,
                    SessionKeys::ACCESS_TOKEN_EXPIRES_AT,
                ]);
            }
        }

        return $next($request);
    }
}
