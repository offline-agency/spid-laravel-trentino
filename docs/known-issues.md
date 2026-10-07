# Known issues

This log tracks behavior that is incorrect, incomplete or surprising, with enough detail to fix it later. It has three parts:

- [Open in 2.0](#open-in-20): issues present in the current code, ordered by severity.
- [Resolved since 2.1](#resolved-since-21): issues of this log fixed after 2.1.0, with the change that fixed them.
- [Resolved in 2.0](#resolved-in-20): discrepancies found in 1.x (`master` before 2.0) and how 2.0 fixed them, kept for teams upgrading from 1.x.

Severity scale:

| Severity | Meaning |
|----------|---------|
| Critical | Breaks authentication for every user or exposes credentials or personal data |
| High | Breaks authentication in a likely configuration, or weakens a security guarantee |
| Medium | Affects some deployments or edge cases; a workaround exists |
| Low | Cosmetic, tooling or documentation issue |

Each entry lists the location, the current behavior, the impact for applications using the package, and a suggested fix. The suggested fixes are descriptions only.

## Open in 2.0

There are no Critical issues open.

### KI-01: AAC claim names are not verified against a real userinfo response

- **Severity:** High
- **Location:** `src/SpidTrentinoUser.php:71` (`enti-codicefiscale`), `src/SpidTrentinoUser.php:272` (`getFiscalNumber()`), `src/Testing/MockOpenIDConnectClient.php`
- **Current behavior:** the fiscal code is read from the `enti-codicefiscale.fiscalCode` claim, and the other AAC claims from `enti-spid`, `enti-acr` and `enti-issuersource`. These names come from the 1.x DTO; they have not been checked against a real AAC Trentino userinfo response. Whether `fiscalCode` carries the `TINIT-` prefix is also unconfirmed.
- **Impact:** if AAC uses different names, every login fails with `AAC did not return a fiscal code for the authenticated user.` (see [troubleshooting](troubleshooting.md#aac-did-not-return-a-fiscal-code)). If the prefix differs, the `fiscal_code` column stores a different format than other systems expect.
- **Suggested fix:** capture an anonymized userinfo response from AAC (test environment), then align `SpidTrentinoUser`, `MockOpenIDConnectClient` and the test fixtures with it.

### KI-02: Nonce is optional and the userinfo subject is not matched

- **Severity:** Medium
- **Location:** `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php:1207`, `src/OpenIdConnect/LaravelOpenIDConnectClient.php:143` (`verifyJWTClaims()`), `src/SpidTrentino.php:59`
- **Current behavior:** the package always sends a `nonce`, but jumbojett accepts an ID token without one. The `sub` returned by the userinfo endpoint is not compared with the `sub` of the verified ID token.
- **Impact:** both checks are required by OpenID Connect Core (3.1.3.7 step 11, 5.3.2). State, PKCE and the confidential client limit the practical risk, but a compromised or misbehaving provider response is not fully rejected.
- **Suggested fix:** in the `verifyJWTClaims()` override, require `nonce` when validating an ID token; in `SpidTrentino::handleCallback()`, compare the userinfo `sub` with `getVerifiedClaims('sub')` and fail on mismatch.

### KI-03: Concurrent refreshes can log the user out

- **Severity:** Medium
- **Location:** `src/Http/Middleware/RefreshSpidTokenIfNeeded.php:33-35`
- **Current behavior:** each request inside the 60 second refresh window refreshes the token independently. If AAC rotates refresh tokens (single use), parallel requests (for example several XHR calls) send the same refresh token; all but one get `invalid_grant`, and the middleware forgets the tokens.
- **Impact:** depending on which request writes the session last, the user may be sent back to the SPID login.
- **Suggested fix:** wrap the refresh in a cache lock keyed by session id and re-read the session after acquiring it, or document AAC's refresh token rotation policy if tokens are reusable.

### KI-04: No configurable HTTP timeout

- **Severity:** Medium
- **Location:** `src/OpenIdConnect/LaravelOpenIDConnectClient.php:271`, `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php:183`
- **Current behavior:** requests to AAC use jumbojett's default timeout of 60 seconds.
- **Impact:** `spid.refresh` runs inside normal page requests, so an unresponsive AAC can stall pages for up to a minute.
- **Suggested fix:** add a `timeout` configuration key and pass it to `setTimeout()` when the client is built.

### KI-05: One login round trip per session

- **Severity:** Medium
- **Location:** `src/OpenIdConnect/LaravelOpenIDConnectClient.php:210` (`setSessionKey()`), `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php:323`
- **Current behavior:** state, nonce and PKCE verifier are stored under fixed session keys. Starting a second login (for example in another tab) overwrites them.
- **Impact:** the first tab's callback fails and the user sees the login error page. Because the code is exchanged before the state is compared ([KI-07](#ki-07-the-authorization-code-is-exchanged-before-the-state-is-checked)), the logged reason is usually AAC's token error (the PKCE verifier no longer matches); it is `Unable to determine state` only when the exchange succeeds.
- **Suggested fix:** store pending authorizations keyed by state value and look them up on callback.

### KI-12: Public clients send an empty secret when refreshing

- **Severity:** Medium
- **Location:** `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php:1000-1001` (`refreshToken()`), `src/SpidTrentinoServiceProvider.php` (`makeClient()` passes `null` for an empty secret)
- **Current behavior:** for the code exchange, jumbojett drops client authentication when PKCE is used and there is no secret. `refreshToken()` has no such branch: with the default `client_secret_basic` method it sends `Authorization: Basic` with the client id and an empty secret.
- **Impact:** with a public client (empty `SPID_TRENTINO_CLIENT_SECRET`), AAC may reject every refresh; users are then sent back to the SPID login when the access token expires. Not tested against AAC.
- **Suggested fix:** for public clients, override `refreshToken()` to send `client_id` in the body without an `Authorization` header; until then, prefer a confidential client.

### KI-13: Transaction log integrity checks depend on the current APP_KEY

- **Severity:** Medium
- **Location:** `src/Models/SpidTransactionLog.php` (`hmac()`, `verifyIntegrity()`)
- **Current behavior:** payloads are encrypted and their HMAC is computed with the current `APP_KEY`. After a key rotation, old rows can still be decrypted when the old key is in `APP_PREVIOUS_KEYS`, but `verifyIntegrity()` recomputes the HMAC with the new key and returns `false`.
- **Impact:** within the 24-month retention, a rotated `APP_KEY` makes older records fail integrity checks even though they were not tampered with.
- **Suggested fix:** store a key identifier with each row and keep a dedicated, versioned HMAC key (for example from configuration) instead of `APP_KEY`.

### KI-14: The transaction log does not record federation trust chains or follow lost sessions

- **Severity:** Low
- **Location:** `src/SpidTrentino.php` (transaction log calls)
- **Current behavior:** the spec also lists the Trust Chain of the entity (Entity Configuration and Entity Statements); AAC is used through plain OIDC discovery, so no trust chain is fetched or logged. When the callback arrives without the session (see `session_fallback`), the transaction id is lost and the callback events start a new transaction id.
- **Impact:** a login may appear as two transactions in the log; records are still complete per message.
- **Suggested fix:** log the discovery document and JWKS as the provider's configuration; carry the transaction id in the session fallback entry.

### KI-06: Key rotation that keeps the same key id is not retried

- **Severity:** Low
- **Location:** `src/OpenIdConnect/LaravelOpenIDConnectClient.php:121` (`verifyJWTSignature()`)
- **Current behavior:** the cached JWKS is dropped and the signature checked again only when jumbojett throws (for example an unknown `kid`). When AAC replaces the key material under the same `kid`, or the token has no `kid`, verification returns false and nothing is retried.
- **Impact:** logins fail for up to `cache_ttl` seconds (3600 by default) after such a rotation.
- **Suggested fix:** also retry once when the parent returns false and a cached JWKS was dropped.

### KI-07: The authorization code is exchanged before the state is checked

- **Severity:** Low
- **Location:** `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php:311` and `:323`
- **Current behavior:** jumbojett calls the token endpoint first and compares `state` afterwards.
- **Impact:** a forged callback consumes an authorization code and triggers a call to AAC before being rejected. The login is still refused.
- **Suggested fix:** in `LaravelOpenIDConnectClient::authenticateWith()`, compare `state` with the stored value before calling the parent when a `code` is present.

### KI-08: Upgrade guide snippet for custom controllers has no error handling

- **Severity:** Low
- **Location:** `UPGRADE.md` ("Custom controllers", `login()` snippet)
- **Current behavior:** the snippet returns `$spid->redirectToLogin()` without catching `OpenIDConnectClientException`.
- **Impact:** an application that copies it shows a 500 page when AAC cannot be reached, instead of the configured error page.
- **Suggested fix:** use the same `try` / `spidLoginFailed()` pattern as `SpidAuthController::login()` (see [extending](extending.md#using-the-trait-in-your-own-controller)).

### KI-09: Quick successive merges can skip a release

- **Severity:** Low
- **Location:** `.github/workflows/release.yml:13-15` (`concurrency: release`, `cancel-in-progress: false`)
- **Current behavior:** GitHub keeps only one pending run per concurrency group. If three pull requests are merged in quick succession, the middle run is cancelled.
- **Impact:** that version number is skipped; its changes ship in the next release.
- **Suggested fix:** accept and document it, or replace the concurrency group with a queue (for example a release job triggered by tags).

### KI-10: Deprecation notices from dependencies in the test output

- **Severity:** Low
- **Location:** `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php` (implicitly nullable parameters), Pest 5.0 arch plugin (`ReflectionProperty::setAccessible()` on PHP 8.5)
- **Current behavior:** the suite reports one deprecation (two on PHP 8.5 with the lowest dependencies). They come from vendor code.
- **Impact:** none at runtime; noise in test output and in the `deprecations` log channel.
- **Suggested fix:** upstream fixes in jumbojett and Pest; nothing to change in this package.

### KI-11: Badges show no data before publication

- **Severity:** Low
- **Location:** `README.md` (badges row)
- **Current behavior:** the Packagist badges need the package to be published on Packagist, and the coverage badge needs the `CODECOV_TOKEN` repository secret.
- **Impact:** badges show "not found" or "unknown" until then.
- **Suggested fix:** submit the package to Packagist and add the secret.

## Resolved since 2.1

| # | Issue | Fixed by |
|---|-------|----------|
| KI-18 | The SPID assurance level and identity source were not enforced | `required_acr` (requested with `acr_values`, enforced on the callback) and `allowed_issuer_sources`, read through configurable `claims.acr` and `claims.issuer_source` paths. The default claim names still depend on [KI-01](#ki-01-aac-claim-names-are-not-verified-against-a-real-userinfo-response), which stays open. See [security](security.md#spid-level-and-identity-provider) |

## Resolved in 2.0

Every discrepancy below was confirmed on `master` before 2.0 (locations refer to that tree). Upgrading to 2.0 fixes them; see [UPGRADE.md](../UPGRADE.md) for the related breaking changes.

| # | 1.x location | 1.x behavior | Fixed in 2.0 by |
|---|--------------|--------------|-----------------|
| R-01 | `src/SpidTrentinoServiceProvider.php:18`, `config/config.php:8`, `README.md:36,39`, `src/Traits/SpidAuthenticatesUsers.php:68` | Config published with tag `config`, documented as `--tag=spid-config` and `config/spid.php`; `redirectTo()` read `spid.redirect_to`, so `redirect_to` was never applied | Tag `spid-laravel-trentino-config`, file `config/spid-laravel-trentino.php`, `redirectTo()` reads `spid-laravel-trentino.redirect_to` |
| R-02 | `src/Http/Middleware/EnsureValidSpidToken.php:25`, `src/SpidTrentino.php:80` | `spid.valid` read `spid_user` while the callback stored `spid_trentino_user`, so every protected request logged the user out | `SessionKeys::USER` used everywhere |
| R-03 | `src/SpidTrentinoFacade.php:16`, `src/SpidTrentinoServiceProvider.php:35` | Facade accessor `spid-trentino`, container binding `spid-laravel-trentino.auth`: the facade did not resolve | Accessor `SpidTrentino::class`, scoped binding |
| R-04 | `src/SpidTrentino.php:120` | `logout()` read `$user->fiscalNumber`, a property that does not exist | `getFiscalNumber()` |
| R-05 | `src/Http/Controllers/SpidAuthController.php:58`, `src/Traits/SpidAuthenticatesUsers.php:55`, `src/SpidTrentino.php:121` | The default logout called the trait's `spidLogout()`, so `SpidTrentinoLoggedOut` was never dispatched | Controller calls `SpidTrentino::logout()`; `spidLogout()` removed |
| R-06 | `README.md:87` | Middleware registration through `Kernel::$routeMiddleware`, which Laravel 11+ does not have | Aliases `spid.valid` and `spid.refresh` registered by the service provider |
| R-07 | `.env.example:3`, `resources/views/components/login-button.blade.php:3-4` | Callback documented as `/aac/callback` (route is `/spid/callback`); Blade button fell back to `/aac/login` and had a stray `"` in its class | `.env.example` uses `/spid/callback`; button uses `route('spid.login')` |
| R-08 | `config/config.php:61`, `src/Http/Controllers/SpidAuthController.php:59` | Logout at `POST /logout` (collides with Breeze, Jetstream, Fortify) and redirect to `/login` (may not exist) | `POST /spid/logout`, redirect to `logout_redirect_to` |
| R-09 | `src/SpidTrentino.php:219`, `:211`, `:174` | JWKS URL hardcoded to `/.well-known/jwks.json`; `is_array()` checks on the `stdClass` token response, so the ID token was never validated and the expiry never stored | jumbojett verifies the ID token with the discovered `jwks_uri`; `TokenResponse` normalizes the response |
| R-10 | jumbojett native session, `src/Http/Controllers/SpidAuthController.php:44-45` | State and PKCE verifier stored in `$_SESSION`, outside the Laravel session (state mismatches behind load balancers, broken under Octane) | `LaravelOpenIDConnectClient` keeps them in the Laravel session |
| R-11 | `src/SpidTrentino.php:83,84,86`, `src/Http/Controllers/SpidAuthController.php:33` | Access token, refresh token and full user payload (fiscal code, names) logged | No tokens or personal data in logs; `LogRedactor` keyed hashes |
| R-12 | `tests/Feature/AacAuthServiceTest.php:6,27`, `composer.json:44`, `src/Testing/MockOpenIDConnectClient.php:36` | Tests used a non-existent `Tests\TestCase`; `autoload-dev` namespace `OfflineAgency\SpidTrentino\Tests`; mocks used `codicefiscale` instead of `enti-codicefiscale` | Testbench suite, namespace `OfflineAgency\SpidLaravelTrentino\Tests`, mocks aligned |
| R-13 | `.github/workflows/test.yml:15,18-19` | CI on PHP 8.1 although `composer.json` required `^8.2`; unused MySQL service | `tests.yml` matrix on PHP 8.4/8.5 and Laravel 12/13, no database service |
| R-14 | `README.md:172,177`, `composer.json:15`, `CHANGELOG.md:5` | Security email `.com` in README vs `.it` in composer; credits linked to `laravel-mongo-auto-sync`; changelog placeholder date `201X-XX-XX` | `support@offlineagency.it` everywhere; credits link fixed; real changelog |
| R-15 | `src/SpidTrentino.php:81-82` and `:189,193` | `refresh_token` and `access_token_expires_at` written twice per callback | Tokens written once by `storeTokens()` |
| R-16 | `src/SpidTrentino.php:24`, `src/Http/Controllers/SpidAuthController.php:27` | OIDC client and service created with `new`, so container bindings could not replace them in tests | Constructor injection, `LaravelOpenIDConnectClient` bound in the container |
