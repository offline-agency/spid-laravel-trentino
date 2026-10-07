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

## Unable to determine state

- **Log:** `[SPID] Authentication failed` with `Unable to determine state`; often `has_state: false` in the diagnostic.
- **Cause:** the `state` returned by AAC does not match the one stored in the session, or the session lost it. Typical reasons:
  - the session cookie is not sent on the callback (cookie domain or path differs between the login and callback URLs, `SESSION_SECURE_COOKIE=true` on HTTP, `SESSION_SAME_SITE=strict`);
  - several application servers with a non-shared session store (`file` driver);
  - the session expired while the user was on AAC (`SESSION_LIFETIME`);
  - the user started a second login in another tab, see [KI-05](known-issues.md#ki-05-one-login-round-trip-per-session);
  - the user reloaded or bookmarked the callback URL (the state is consumed by the first callback).
- **Fix:** use a shared session store, keep login and callback on the same host and scheme, keep `SameSite=Lax`, and start a new login from `spid.login`.

## Redirect URI mismatch

- **Log:** AAC shows an error page instead of the login form, or the callback logs `Error: invalid_request` / `Error: invalid_grant` with a description mentioning the redirect URI.
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
- **Cause:** network problems, DNS, firewall, AAC maintenance, or a proxy returning an HTML page.
- **Fix:** check `SPID_TRENTINO_PROVIDER_URL` and outbound connectivity from the server (`curl {provider_url}/.well-known/openid-configuration`). Invalid documents are never cached, so logins recover as soon as AAC does.

## ID token rejected

- **Log:** `Unable to verify signature`, `Unable to verify JWT claims`, `Unable to find a key for ...` or `User did not authorize openid scope.`
- **Cause:**
  - signature: the token was not signed with a key in AAC's JWKS (wrong environment, or a key rotation under the same key id, see [KI-06](known-issues.md#ki-06-key-rotation-that-keeps-the-same-key-id-is-not-retried));
  - claims: wrong issuer (`provider_url` does not match the token `iss`), the client id is missing from `aud`, the token has no `exp` or is expired, or the `nonce` does not match;
  - clock skew larger than 300 seconds between your server and AAC;
  - `openid` missing from the scopes granted to the client.
- **Fix:** check `SPID_TRENTINO_PROVIDER_URL` (test vs production), `SPID_TRENTINO_CLIENT_ID`, the server clock (NTP), and the scopes registered on AAC. To force a JWKS refresh, clear the cache (`php artisan cache:clear`) or wait `cache_ttl` seconds.

## AAC did not return a fiscal code

- **Log:** `AAC did not return a fiscal code for the authenticated user.`
- **Cause:** the userinfo response has no non-empty `enti-codicefiscale.fiscalCode`. Usually the `profile.codicefiscale.me` scope is not granted to the client, or AAC uses a different claim name ([KI-01](known-issues.md#ki-01-aac-claim-names-are-not-verified-against-a-real-userinfo-response)).
- **Fix:** check the scopes on AAC and in `SPID_TRENTINO_SCOPES`. Without a fiscal code the package refuses the login on purpose, so it can never match an unrelated local account.

## Users are logged out after a while

- **Log:** `[SPID] Access token refresh failed` with `AAC refused the token refresh: invalid_grant` (or `no access token returned`, or a connection error).
- **Cause:** the refresh token expired or was revoked, `offline_access` was not granted (no refresh token), or concurrent refreshes with single-use refresh tokens ([KI-03](known-issues.md#ki-03-concurrent-refreshes-can-log-the-user-out)).
- **Fix:** request `offline_access`; this behavior is otherwise expected: the tokens are forgotten and `spid.valid` sends the user to `spid.login`.

## Users are sent back to the SPID login on every request

- **Log:** no error; `spid.valid` redirects.
- **Cause:** `spid.valid` requires both `SessionKeys::USER` and `SessionKeys::ACCESS_TOKEN`. Common reasons: the route uses `spid.valid` without `web` (no session), `spid.valid` is placed before `spid.refresh` and the token just expired, or the session driver does not persist between requests.
- **Fix:** use `['web', 'auth', 'spid.refresh', 'spid.valid']`, see [middleware](middleware.md).

## The user model is rejected

- **Log:** `The user model [...] must be an Eloquent model.` or `... must implement Authenticatable.` (a `LogicException`, so a 500 page).
- **Cause:** `auth.providers.users.model` points to a class that is not an authenticatable Eloquent model.
- **Fix:** see [user model](user-model.md).

## Other errors

| Message | Cause | Fix |
|---------|-------|-----|
| `Configuration value for key [spid-laravel-trentino.client_id] must be a string, NULL given.` | `SPID_TRENTINO_CLIENT_ID` is not set (or the config cache is stale) | Set it and run `php artisan config:clear` |
| `The provider ... could not be fetched. Make sure your provider has a well known configuration available.` | The discovery document lacks a required endpoint | Check `SPID_TRENTINO_PROVIDER_URL` points to the AAC base URL |
| `No application encryption key has been specified.` | `APP_KEY` missing (needed for sessions and `LogRedactor`) | `php artisan key:generate` |
| `Route [spid.login] not defined.` | `register_routes` is `false` and no route is named `spid.login` | Register your routes with the package names, see [extending](extending.md#registering-your-own-routes) |
| Changes to `.env` have no effect | Configuration is cached | `php artisan config:clear` |
