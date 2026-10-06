<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session when the SPID user or access token is missing, or when the
 * access token has expired. HTML requests are redirected to the SPID login;
 * JSON requests get 419 so the client can re-authenticate.
 */
class EnsureValidSpidToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Session::has(SessionKeys::USER) && Session::has(SessionKeys::ACCESS_TOKEN) && ! SessionExpiry::hasExpired()) {
            return $next($request);
        }

        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        if ($request->expectsJson()) {
            return ResponseFactory::json(['message' => 'SPID session missing or expired.'], 419);
        }

        return Redirect::guest(URL::route('spid.login'));
    }
}
