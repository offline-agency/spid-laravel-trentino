# Session and tokens

Everything the package stores lives in the Laravel session. Use the constants of `OfflineAgency\SpidLaravelTrentino\SessionKeys` instead of literal keys.

## Session keys

| Constant | Key | Content | Written by | Cleared by |
|----------|-----|---------|------------|------------|
| `SessionKeys::USER` | `spid_trentino_user` | SPID payload, `SpidTrentinoUser::toArray()` | `SpidTrentino::handleCallback()` | `SpidTrentino::logout()`, `spid.valid` (session invalidated) |
| `SessionKeys::ACCESS_TOKEN` | `spid_trentino_access_token` | AAC access token | callback, refresh | logout, `spid.valid`, failed refresh |
| `SessionKeys::REFRESH_TOKEN` | `spid_trentino_refresh_token` | AAC refresh token, when issued (`offline_access` scope) | callback, refresh | logout, `spid.valid`, failed refresh, a callback without refresh token |
| `SessionKeys::ACCESS_TOKEN_EXPIRES_AT` | `spid_trentino_access_token_expires_at` | ISO-8601 expiry of the access token | callback, refresh (when AAC sends `expires_in`) | logout, `spid.valid`, failed refresh, a response without `expires_in` |
| `SessionKeys::ERROR` | `spid_trentino_error` | Flash message `SPID authentication failed. Please try again.` | `spidLoginFailed()` | next request (flash) |
| `SessionKeys::OIDC_PREFIX` + `openid_connect_state` | `spid_trentino_oidc_openid_connect_state` | OIDC `state` of the pending login | login redirect | callback (verified and removed) |
| `SessionKeys::OIDC_PREFIX` + `openid_connect_nonce` | `spid_trentino_oidc_openid_connect_nonce` | OIDC `nonce` of the pending login | login redirect | callback |
| `SessionKeys::OIDC_PREFIX` + `openid_connect_code_verifier` | `spid_trentino_oidc_openid_connect_code_verifier` | PKCE code verifier of the pending login | login redirect | callback |

The package does not use the native PHP session (`$_SESSION`). In 1.x the OIDC state lived there, which caused state mismatches; see [known issues R-10](known-issues.md#resolved-in-20).

Reading the session in your application:

```php
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

$spidUser = SpidTrentinoUser::fromArray(session(SessionKeys::USER, []));
$accessToken = session(SessionKeys::ACCESS_TOKEN);
```

## Token expiry

The expiry is computed from `expires_in` of the token response and stored as an ISO-8601 string. `OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry` is the only class that reads and writes it:

| Method | Returns |
|--------|---------|
| `SessionExpiry::get()` | The expiry as `CarbonImmutable`, or `null`. Accepts the formats 1.x stored too: Carbon or `DateTimeInterface` instances, date strings, unix timestamps. |
| `SessionExpiry::hasExpired()` | `true` when an expiry is stored and has passed, **or cannot be parsed**. `false` when no expiry is stored. |
| `SessionExpiry::expiresWithin($seconds)` | `true` when a known expiry falls within the next `$seconds`. |
| `SessionExpiry::put($expiresAt)` | Stores the expiry, or forgets it when `null`. |

## Refresh

`spid.refresh` (`RefreshSpidTokenIfNeeded`) refreshes the access token when:

- the session holds a refresh token, and
- the expiry is known and falls within the next 60 seconds (`REFRESH_WINDOW_SECONDS`), including tokens that have already expired.

`SpidTrentino::refreshAccessToken()` then:

1. Sends the refresh token to the AAC token endpoint (`grant_type=refresh_token`).
2. On success stores the new access token and expiry. If AAC returns a new refresh token it replaces the old one; otherwise the old one is kept.
3. On failure (an error response such as `invalid_grant`, no access token, AAC unreachable) throws `OpenIDConnectClientException`.

The middleware catches the exception, logs `[SPID] Access token refresh failed` and forgets the access token, refresh token and expiry. On the same request `spid.valid` (placed after `spid.refresh`) finds no access token and ends the session. See [middleware](middleware.md).

> Parallel requests inside the refresh window refresh independently. With single-use refresh tokens this can log the user out, see [KI-03](known-issues.md#ki-03-concurrent-refreshes-can-log-the-user-out).

## Session requirements

- Any Laravel session driver works, but the session must survive the round trip to AAC: the login request writes the state, and the callback (a different request, possibly minutes later) reads it.
- With several application servers, use a shared session store (database, Redis), not `file` on local disks.
- The session cookie must be sent on the callback. AAC redirects back with a top-level GET, which `SameSite=Lax` (Laravel's default) allows.
- Only one login can be pending per session at a time, see [KI-05](known-issues.md#ki-05-one-login-round-trip-per-session).
- If the cookie is lost between login and callback in your users' network, `session_fallback` can recover the pending login from the cache (opt-in, single use, bound to the client IP address and user agent), see [security](security.md#session-fallback-for-lost-cookies).
