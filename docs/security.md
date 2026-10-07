# Security

This page describes what the package protects against, what it leaves to your application, and the known gaps. To report a vulnerability, see [SECURITY.md](../SECURITY.md).

## Authorization request

- **PKCE:** the client always asks for the `S256` method. jumbojett uses it when AAC lists `S256` in `code_challenge_methods_supported` of the discovery document; the code verifier is stored in the session and sent with the token request.
- **State:** a random `state` is stored in the session and compared on callback (`Unable to determine state` on mismatch). It is removed after use, so a callback cannot be replayed. The code is exchanged before the state is compared, see [KI-07](known-issues.md#ki-07-the-authorization-code-is-exchanged-before-the-state-is-checked).
- **Nonce:** a random `nonce` is sent and compared with the ID token claim when the claim is present, see [KI-02](known-issues.md#ki-02-nonce-is-optional-and-the-userinfo-subject-is-not-matched).
- **Callback parameters:** only `code`, `state`, `error` and `error_description` are read, and only when they are strings; other request input never reaches the OIDC library.

## ID token validation

Validation happens inside jumbojett's `authenticate()`, before any session or user is touched:

| Check | Done by |
|-------|---------|
| Signature (RS*/PS* with keys from the discovered `jwks_uri`, HS* with the client secret) | jumbojett |
| `iss` equals `provider_url` or the discovery `issuer` | jumbojett |
| `aud` contains the client id | jumbojett, plus a pre-check by the package that avoids a `TypeError` on a foreign string `aud` |
| `sub` present and a string | package |
| `exp` present, an integer, not expired (300 s leeway) | package requires it; jumbojett checks expiry |
| `nbf`, `at_hash` when present | jumbojett |
| `nonce` when present | jumbojett |

The package does not validate the ID token a second time with another library. Gaps: an ID token without `nonce` is accepted, and the userinfo `sub` is not compared with the ID token `sub` ([KI-02](known-issues.md#ki-02-nonce-is-optional-and-the-userinfo-subject-is-not-matched)).

## Account matching

- Local users are matched only by fiscal code (`fiscal_code`). A userinfo response without a non-empty fiscal code fails the login, so it cannot match an account with an empty or null fiscal code.
- The SPID email is copied only when no other user owns it. The package never links or takes over an existing account by email.

## Session

- **Fixation:** at login `Auth::login()` migrates the session id and `authenticateFromSpid()` regenerates it again.
- **Logout:** `SpidTrentino::logout()` and `spid.valid` invalidate the session and regenerate the CSRF token.
- **Open redirects:** post-login and post-logout targets come from configuration (`redirect_to`, `logout_redirect_to`, `error_redirect_to`) or from the intended URL stored server side by Laravel; no target is read from request parameters.
- The logout ends the application session only; the SPID session at the identity provider stays active.

## Logging and personal data

- Tokens (access, refresh, ID), the client secret and personal data (fiscal code, names, email) are never logged by the package.
- Users are identified in log context through `OfflineAgency\SpidLaravelTrentino\Support\LogRedactor`: an HMAC-SHA256 of the value keyed with `app.key`, truncated to 16 hex characters. Fiscal codes have little entropy, so an unkeyed hash would be reversible; the keyed hash is not, as long as `APP_KEY` stays secret.
- `[SPID] Callback diagnostic` logs only booleans and the keyed hash of the session id.
- `[SPID] Authentication failed` logs the exception. Exception messages from the package and jumbojett contain no tokens. Stack traces may contain function arguments (for example the authorization code) when `zend.exception_ignore_args` is `Off`; PHP production defaults set it to `On`.
- Use `LogRedactor::user($spidUser)` in your own listeners instead of logging the DTO.

## HTTP and caching

- All calls to AAC go through the Laravel HTTP client with TLS verification on (Laravel's default).
- Only unauthenticated GET responses that are JSON objects are cached (the discovery document and the JWKS), for `cache_ttl` seconds in the default cache store. Token requests and userinfo calls are never cached.
- Use a cache store that is not shared with untrusted applications (or set a cache prefix), since a poisoned discovery document or JWKS would redirect the trust anchor.

## Production checklist

- Serve the application over HTTPS and set `SESSION_SECURE_COOKIE=true`.
- Point `SPID_TRENTINO_PROVIDER_URL` to the production AAC (the default is the test environment).
- Keep `SPID_TRENTINO_CLIENT_SECRET` and `APP_KEY` out of version control.
- Set `APP_DEBUG=false`; set `LOG_LEVEL` to `info` or higher.
- With several servers, use a shared session store and a shared cache store.
- Keep the server clock in sync (NTP); ID token checks allow 300 seconds of skew.
- Protect routes with `['web', 'auth', 'spid.refresh', 'spid.valid']`.
