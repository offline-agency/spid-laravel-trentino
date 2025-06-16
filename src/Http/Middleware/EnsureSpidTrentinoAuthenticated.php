<?php
declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks that a SPID payload is still present in the session.
 * – If the payload is missing (or, when available, the access-token is
 *   already expired) the user is logged out and the session is flushed.
 * – For JSON/AJAX calls it returns **419 (Page Expired)** so the client-side
 *   app can refresh or redirect; otherwise it redirects to the SPID
 *   login route.
 */
class EnsureValidSpidToken
{
  public function handle(Request $request, Closure $next)
  {
    /** SPID payload saved during login */
    $token = Session::get('spid_user');

    /** Optional: expiry timestamp, saved only if your flow provides it */
    $expiresAt = Session::get('access_token_expires_at');   // may be null

    $isExpired = $expiresAt && now()->greaterThanOrEqualTo($expiresAt);

    // Missing or expired token  ➜  force logout
    if (! $token || $isExpired) {
      Auth::logout();
      Session::flush();

      if ($request->expectsJson()) {
        return response()->json(
          ['message' => 'SPID token missing or expired.'],
          419
        );
      }

      return redirect()->guest(route('spid.login'));
    }

    // Token looks valid – continue
    return $next($request);
  }
}
