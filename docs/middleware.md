# Middleware

The service provider registers two route middleware aliases. You do not need to touch `bootstrap/app.php` unless package discovery is disabled (see [installation](installation.md#registering-the-middleware-manually-laravel-12-and-13)).

| Alias | Class |
|-------|-------|
| `spid.refresh` | `OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded` |
| `spid.valid` | `OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken` |

## Recommended usage

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->group(function () {
    Route::get('/area-riservata', fn () => view('private.dashboard'));
});
```

Order matters:

- `web` starts the session both middleware read.
- `auth` sends guests to your login page (store the intended URL there).
- `spid.refresh` must run **before** `spid.valid`: a token about to expire is refreshed first, and a failed refresh is detected by `spid.valid` on the same request.

## `spid.refresh`: RefreshSpidTokenIfNeeded

Runs only when the session has a refresh token **and** the access token expires within 60 seconds (or has already expired). Otherwise it does nothing.

| Case | Behavior |
|------|----------|
| No refresh token, or no known expiry | Request continues untouched; no call to AAC. |
| Expiry more than 60 s away | Request continues untouched. |
| Expiry within 60 s, refresh succeeds | New access token (and refresh token, if AAC rotates it) and expiry stored; request continues. |
| Refresh fails (`invalid_grant`, error response, AAC unreachable) | Logs `[SPID] Access token refresh failed`, forgets access token, refresh token and expiry; request continues (and `spid.valid` ends the session). |

The middleware never blocks the request by itself. Token refresh details are in [session and tokens](session-and-tokens.md#refresh).

## `spid.valid`: EnsureValidSpidToken

Lets the request through only when the session has the SPID user (`SessionKeys::USER`), an access token (`SessionKeys::ACCESS_TOKEN`), and the access token has not expired. An expiry that cannot be parsed counts as expired; a missing expiry does not.

Otherwise it ends the session:

1. `Auth::logout()`
2. the session is invalidated and the CSRF token regenerated
3. the response depends on the request:

| Request | Response |
|---------|----------|
| Expects JSON (`Accept: application/json`, XHR with JSON) | `419` with `{"message": "SPID session missing or expired."}` |
| Anything else | Redirect to `route('spid.login')`, storing the current URL as intended |

A JavaScript client can treat `419` as "log in again" and redirect the window to `spid.login`.

If you set `register_routes` to `false`, keep a route named `spid.login`: `spid.valid` builds its redirect with `URL::route('spid.login')`.

## Limitations

- Parallel requests within the refresh window refresh independently, see [KI-03](known-issues.md#ki-03-concurrent-refreshes-can-log-the-user-out).
- A slow or unresponsive AAC delays the request that triggers the refresh (60 second HTTP timeout), see [KI-04](known-issues.md#ki-04-no-configurable-http-timeout).
- `spid.valid` checks what is stored in the session; it does not ask AAC whether the token was revoked.
