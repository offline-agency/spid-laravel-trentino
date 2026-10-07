# Troubleshooting

When a SPID login fails, the user lands on `error_redirect_to` with the flash message `SPID authentication failed. Please try again.`, and the reason is in your application log:

```text
local.ERROR: [SPID] Authentication failed {"exception":"[object] (Jumbojett\\OpenIDConnectClientException(code: 0): <message> at ...)"}
```

Every callback also logs `[SPID] Callback diagnostic` **before** the code exchange:

```text
local.INFO: [SPID] Callback diagnostic {"session_id_hash":"1f3a...","has_state":true,"has_code_verifier":true,"request_has_code":true,"request_has_state":true}
```

| Field | Meaning |
|-------|---------|
| `session_id_hash` | Keyed hash of the session id: compare it with the one of the login request to see whether the browser kept the same session |
| `has_state`, `has_code_verifier` | Whether the session still holds the values written by the login redirect |
| `request_has_code`, `request_has_state` | Whether AAC sent `code` and `state` back |

Find the `<message>` below.

## Login fails after returning from AAC (session lost, second tab, reloaded callback)

jumbojett exchanges the authorization code **before** it compares `state` ([KI-07](known-issues.md#ki-07-the-authorization-code-is-exchanged-before-the-state-is-checked)), so these problems usually show up as a token error from AAC, not as a state error.

- **Log:**
  - `[SPID] Callback diagnostic` with `has_state: false` and `has_code_verifier: false` (session lost), or with both `true` (second tab or reloaded callback);
  - then `[SPID] Authentication failed` with AAC's `error_description` for the token request (for example a PKCE or expired-code message) or `Got response: invalid_grant`;
  - only when the code exchange still succeeds (for example AAC does not use PKCE) is the message `Unable to determine state`.
- **Cause:**
  - the session cookie is not sent on the callback (cookie domain or path differs between the login and callback URLs, `SESSION_SECURE_COOKIE=true` on HTTP, `SESSION_SAME_SITE=strict`), so the PKCE verifier and the state are missing;
  - several application servers with a non-shared session store (`file` driver);
  - the session expired while the user was on AAC (`SESSION_LIFETIME`);
  - the user started a second login in another tab, which replaced the stored verifier and state, see [KI-05](known-issues.md#ki-05-one-login-round-trip-per-session);
  - the user reloaded or bookmarked the callback URL (the code was already redeemed).
- **Fix:** use a shared session store, keep login and callback on the same host and scheme, keep `SameSite=Lax`, and start a new login from `spid.login`. If a proxy in your users' network strips the cookie and cannot be fixed, consider `session_fallback` (read its [trade-off](security.md#session-fallback-for-lost-cookies) first); with it enabled, recovered values log `[SPID] Session fallback used`.

## Redirect URI mismatch

- **Log:** AAC shows an error page instead of the login form; or AAC redirects back with an error and the callback logs `Error: invalid_request` (an `error` sent on the callback URL is logged as `Error: <error>` followed by ` Description: <description>`); or the token request fails and the log shows AAC's `error_description` or `Got response: invalid_grant`.
- **Cause:** the redirect URI sent by the package differs from the one registered on AAC (scheme, host, port, path or trailing slash).
- **Fix:** set `SPID_TRENTINO_REDIRECT_URI` to exactly the registered value, or register the URL of the `spid.callback` route. Behind a proxy, make sure Laravel generates `https` URLs (trusted proxies) when the variable is empty.

## The user cancelled on AAC

- **Log:** `Error: access_denied` (optionally followed by `Description: ...`).
- **Cause:** AAC redirected back with an `error` parameter.
- **Fix:** nothing to fix; the user sees the error page and can try again.

## AAC unreachable or returning an error page

- **Log:** one of
  - `Unable to reach AAC: <cURL error>`
  - `AAC returned HTTP 503 for https://.../.well-known/openid-configuration`
  - `AAC returned an invalid document for https://.../.well-known/openid-configuration`
  - `AAC returned an invalid token response (HTTP 502).`
  - `AAC returned a token response without access_token or id_token.`
- **Cause:** network problems, DNS, firewall, AAC maintenance, or a proxy returning an HTML page. A token response without `id_token` also means the `openid` scope was not granted to the client: check the scopes registered on AAC.
- **Fix:** check `SPID_TRENTINO_PROVIDER_URL` and outbound connectivity from the server (`curl {provider_url}/.well-known/openid-configuration`). Invalid documents are never cached, so logins recover as soon as AAC does.

## ID token rejected

- **Log:** `Unable to verify signature`, `Unable to verify JWT claims` or `Unable to find a key for ...`
- **Cause:**
  - signature: the token was not signed with a key in AAC's JWKS (wrong environment, or a key rotation under the same key id, see [KI-06](known-issues.md#ki-06-key-rotation-that-keeps-the-same-key-id-is-not-retried));
  - claims: wrong issuer (`provider_url` does not match the token `iss`), the client id is missing from `aud`, the token has no `exp` or is expired, or the `nonce` does not match;
  - clock skew larger than 300 seconds between your server and AAC.
- **Fix:** check `SPID_TRENTINO_PROVIDER_URL` (test vs production), `SPID_TRENTINO_CLIENT_ID` and the server clock (NTP). To force a JWKS refresh, clear the cache (`php artisan cache:clear`) or wait `cache_ttl` seconds.

## AAC did not return a fiscal code

- **Log:** `AAC did not return a fiscal code for the authenticated user.`
- **Cause:** the userinfo response has no non-empty `enti-codicefiscale.fiscalCode`. Usually the `profile.codicefiscale.me` scope is not granted to the client, or AAC uses a different claim name ([KI-01](known-issues.md#ki-01-aac-claim-names-are-not-verified-against-a-real-userinfo-response)).
- **Fix:** check the scopes on AAC and in `SPID_TRENTINO_SCOPES`. Without a fiscal code the package refuses the login on purpose, so it can never match an unrelated local account.

## Users are logged out after a while

Two different situations:

- **With a log line:** `[SPID] Access token refresh failed` with `AAC refused the token refresh: invalid_grant` (or `no access token returned`, or a connection error).
  - **Cause:** the refresh token expired or was revoked, concurrent refreshes with single-use refresh tokens ([KI-03](known-issues.md#ki-03-concurrent-refreshes-can-log-the-user-out)), or a public client refreshing without a secret ([KI-12](known-issues.md#ki-12-public-clients-send-an-empty-secret-when-refreshing)).
  - **Fix:** expected behavior otherwise: the tokens are forgotten and `spid.valid` sends the user to `spid.login`.
- **Without any log line:** the session has no refresh token, so `spid.refresh` does nothing and `spid.valid` ends the session as soon as the access token expires.
  - **Cause:** `offline_access` was not requested or not granted to the client.
  - **Fix:** add `offline_access` to `SPID_TRENTINO_SCOPES` and to the client on AAC.

## Users are sent back to the SPID login on every request

- **Log:** no error; `spid.valid` redirects.
- **Cause:** `spid.valid` requires both `SessionKeys::USER` and `SessionKeys::ACCESS_TOKEN`. Common reasons: the route uses `spid.valid` without `web` (no session), `spid.valid` is placed before `spid.refresh` and the token just expired, or the session driver does not persist between requests.
- **Fix:** use `['web', 'auth', 'spid.refresh', 'spid.valid']`, see [middleware](middleware.md).

## The user model is rejected

- **Log:** `The user model [...] must be an Eloquent model.` or `... must implement Authenticatable.` (a `LogicException`, so a 500 page).
- **Cause:** `auth.providers.users.model` points to a class that is not an authenticatable Eloquent model.
- **Fix:** see [user model](user-model.md).

## Login rejected for the SPID level or the identity provider

- **Message:** `Your SPID login does not meet the security level required by this service.` or `Your identity provider is not accepted by this service.`
- **Log:** `[SPID] Login rejected` with `reason` (`acr` or `issuer_source`) and one of:
  - `The SPID level <acr> is below the required SpidLn.`: the user logged in at a lower level; AAC should have asked for the required one (check that the login request carries `acr_values`).
  - `The SPID level of the login could not be determined (required: SpidLn).`: neither the ID token `acr` nor `enti-acr.acr` carries a SPID level. Inspect the `token_response` and `userinfo_response` rows of the [transaction log](transaction-log.md) and adjust `claims.acr`.
  - `The identity provider [<source>] is not allowed.` or `The identity provider of the login could not be determined.`: check `allowed_issuer_sources` and `claims.issuer_source`.

## Other errors

| Message | Cause | Fix |
|---------|-------|-----|
| `Configuration value for key [spid-laravel-trentino.client_id] must be a string, NULL given.` | `SPID_TRENTINO_CLIENT_ID` is not set (or the config cache is stale) | Set it and run `php artisan config:clear` |
| `The provider ... could not be fetched. Make sure your provider has a well known configuration available.` | The discovery document lacks a required endpoint | Check `SPID_TRENTINO_PROVIDER_URL` points to the AAC base URL |
| `No application encryption key has been specified.` | `APP_KEY` missing (needed to encrypt cookies and sessions) | `php artisan key:generate` |
| `Route [spid.login] not defined.` | `register_routes` is `false` and no route is named `spid.login` | Register your routes with the package names, see [extending](extending.md#registering-your-own-routes) |
| Changes to `.env` have no effect | Configuration is cached | `php artisan config:clear` |
