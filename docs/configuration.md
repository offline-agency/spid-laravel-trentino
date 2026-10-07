# Configuration

All settings live in `config/spid-laravel-trentino.php` under the `spid-laravel-trentino` namespace (`config('spid-laravel-trentino.client_id')`). Publish the file with:

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-config
```

## Reference

| Key | Type | Default | Env var | Effect |
|-----|------|---------|---------|--------|
| `client_id` | string | `null` | `SPID_TRENTINO_CLIENT_ID` | OIDC client id. Required: resolving the OIDC client throws an `InvalidArgumentException` when it is `null`. |
| `client_secret` | string or null | `null` | `SPID_TRENTINO_CLIENT_SECRET` | Client secret. An empty string is treated as `null` (public client, PKCE only; refresh is untested, see [KI-12](known-issues.md#ki-12-public-clients-send-an-empty-secret-when-refreshing)). |
| `redirect_uri` | string or null | `null` | `SPID_TRENTINO_REDIRECT_URI` | Redirect URI sent to AAC. `null` or empty uses `URL::to()` of `routes.callback`. Must match the URI registered on AAC. |
| `provider_url` | string | `https://aac-test.cloud-test.tndigit.it` | `SPID_TRENTINO_PROVIDER_URL` | AAC base URL. Discovery is read from `{provider_url}/.well-known/openid-configuration`; it is also the expected ID token issuer. |
| `scopes` | string | `openid profile.codicefiscale.me email offline_access` | `SPID_TRENTINO_SCOPES` | Space separated scopes. `openid` is removed from the list and always added by the client, so it is never sent twice. |
| `cache_ttl` | int | `3600` | none | Seconds the discovery document and the JWKS are cached in the default cache store. `0` disables caching. A non-numeric value falls back to `3600`. |
| `session_fallback` | bool | `false` | `SPID_TRENTINO_SESSION_FALLBACK` | Recover a login whose callback arrives without the session cookie. Opt-in; see [security](security.md#session-fallback-for-lost-cookies) before enabling it. Accepts `true`, `1`, `on`, `yes`. |
| `transaction_log.enabled` | bool | `true` | `SPID_TRENTINO_TRANSACTION_LOG_ENABLED` | Write the SPID/CIE OIDC [transaction log](transaction-log.md). Disable only in development and tests. |
| `transaction_log.table` | string | `spid_transaction_logs` | `SPID_TRENTINO_TRANSACTION_LOG_TABLE` | Table of the transaction log (read by the migration and the model). |
| `transaction_log.retention_months` | int | `24` | `SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS` | Retention used by `spid:prune-logs`; values below 24 are raised to 24. |
| `transaction_log.fail_closed` | bool | `false` | `SPID_TRENTINO_TRANSACTION_LOG_FAIL_CLOSED` | `true` aborts a login or token refresh whose log row cannot be written; `false` logs the failure and continues. Logout is never blocked. See [failure handling](transaction-log.md#failure-handling). |
| `register_routes` | bool | `true` | none | `false` skips loading the package routes; register your own with the same names. |
| `routes.login` | string | `/spid/login` | none | Path of the `spid.login` route (GET). |
| `routes.callback` | string | `/spid/callback` | none | Path of the `spid.callback` route (GET). |
| `routes.logout` | string | `/spid/logout` | none | Path of the `spid.logout` route (POST). |
| `auth_controller` | class-string | `SpidAuthController::class` | none | Controller used by the three routes. See [extending](extending.md). |
| `redirect_to` | string or null | `null` | `SPID_TRENTINO_REDIRECT_TO` | Path used after login when the session has no intended URL. `null` or empty uses the fallback logic in `redirectTo()`. |
| `logout_redirect_to` | string | `/` | none | Path used after `POST spid.logout`. |
| `error_redirect_to` | string | `/` | none | Path used when the SPID login fails; the message is flashed under `SessionKeys::ERROR`. |

## Routes

The routes file is loaded only when `register_routes` is `true`. All three routes use the `web` middleware group:

| Method | Path (default) | Name | Controller method |
|--------|----------------|------|-------------------|
| GET | `/spid/login` | `spid.login` | `login` |
| GET | `/spid/callback` | `spid.callback` | `callback` |
| POST | `/spid/logout` | `spid.logout` | `logout` |

`spid.valid` redirects guests to `route('spid.login')`, so when you register your own routes keep the name `spid.login`.

## How values are read

The OIDC client is built by `SpidTrentinoServiceProvider` each time it is resolved from the container:

- `provider_url` and `client_id` are read with `Config::string()`, so a missing (`null`) value throws instead of silently connecting to the wrong place.
- `client_secret` is passed only when it is a non-empty string.
- `cache_ttl` is cast to `int` when numeric, otherwise `3600` is used.
- `session_fallback` is read with `FILTER_VALIDATE_BOOL`, so environment strings such as `true` or `1` enable it and anything else disables it.
- `redirect_uri` falls back to the absolute URL of `routes.callback`.
- PKCE is always requested with `S256`; it is used when AAC advertises `S256` in `code_challenge_methods_supported`.

`redirect_to`, `logout_redirect_to` and `error_redirect_to` are read at request time, so runtime changes with `config()->set()` take effect immediately.

## Post-login redirect

After a successful callback the controller returns `Redirect::intended($this->redirectTo())`. `redirectTo()` (from the `SpidAuthenticatesUsers` trait) returns, in order:

1. `redirect_to`, when it is a non-empty string;
2. `/admin/dashboard`, when the authenticated user has a `hasRole()` method and `hasRole('admin')` is true;
3. `/`.

An intended URL stored by `auth` or `spid.valid` (the page the user tried to open) always wins over `redirectTo()`.

> In 1.x `redirectTo()` read `spid.redirect_to` instead of `spid-laravel-trentino.redirect_to`, so the setting was ignored. See [known issues R-01](known-issues.md#resolved-in-20).

## Caching configuration

The package reads configuration through the `Config` facade, so `php artisan config:cache` works. Remember to clear or rebuild the cache after changing `.env`.
