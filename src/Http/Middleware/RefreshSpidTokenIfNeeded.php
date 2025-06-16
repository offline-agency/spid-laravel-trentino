<?php
declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

/**
 * Transparently refresh the SPID access-token if it is close to expiry.
 *
 * Logic:
 *   • If `refresh_token` or `access_token_expires_at` are **missing** ➜ do nothing.
 *   • If the expiry timestamp is within the next minute ➜ call SpidTrentino::refreshAccessToken().
 *   • On failure we delete the session tokens and continue; subsequent requests
 *     will be caught by the EnsureValidSpidToken middleware.
 */
class RefreshSpidTokenIfNeeded
{
  /**
   * @throws \Throwable – Let any non-OpenID exceptions bubble up (Laravel will handle them)
   */
  public function handle(Request $request, Closure $next)
  {
    try {
      // Both values must exist to even attempt a refresh
      if (Session::has('refresh_token') && Session::has('access_token_expires_at')) {

        /** @var string|\DateTimeInterface $rawExpiry */
        $rawExpiry = Session::get('access_token_expires_at');

        $expiresAt = $rawExpiry instanceof Carbon
          ? $rawExpiry
          : Carbon::parse($rawExpiry);

        // Refresh one minute before the actual expiry
        if (now()->greaterThanOrEqualTo($expiresAt->copy()->subMinute())) {
          /** @var SpidTrentino $spid */
          $spid = app(SpidTrentino::class);
          $spid->refreshAccessToken();
        }
      }
    } catch (OpenIDConnectClientException $e) {
      // Something went wrong while talking to AAC
      Log::error('[SPID] Access-token refresh error: '.$e->getMessage());

      // Purge session data so the next request triggers a full re-authentication
      Session::forget([
        'access_token',
        'refresh_token',
        'access_token_expires_at',
      ]);
    }

    return $next($request);
  }
}
