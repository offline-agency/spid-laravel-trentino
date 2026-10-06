# Changelog

All notable changes to `offline-agency/spid-laravel-trentino` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From 2.0.0 on, every GitHub Release also carries automatically generated notes.

## [2.0.0] - Unreleased

See [UPGRADE.md](UPGRADE.md) for the breaking changes.

### Added
- `LaravelOpenIDConnectClient`: jumbojett 1.x adapted to Laravel (Laravel session instead of the native session, redirects returned as responses instead of `exit`, Laravel HTTP client).
- Discovery document and JWKS caching (`cache_ttl`), with one forced JWKS refresh when AAC rotates its signing key.
- `SessionKeys` constants for every session key and `SessionExpiry` to read and compare the token expiry.
- `TokenResponse` to normalize token endpoint responses, and `LogRedactor` for pseudonymized log context.
- Publishable migration (`spid-laravel-trentino-migrations`) and views (`spid-laravel-trentino-views`).
- Automatic registration of the `spid.valid` and `spid.refresh` middleware aliases.
- `register_routes`, `logout_redirect_to` and `error_redirect_to` configuration keys.
- `email` claim in `SpidTrentinoUser`.
- CI matrix (PHP 8.4/8.5 x Laravel 12/13, lowest and stable), 100% coverage gate, PHPStan level max, Pint, automatic releases from Conventional Commit PR titles, Dependabot, issue and PR templates, `publiccode.yml`.

### Changed
- Requires PHP 8.4+ and Laravel 12.69+ or 13.30+; depends on `illuminate/*` components instead of `laravel/framework`.
- `jumbojett/openid-connect-php` 1.x.
- Configuration file renamed to `config/spid-laravel-trentino.php`, published with the `spid-laravel-trentino-config` tag.
- Default logout route moved to `POST /spid/logout`.
- Session keys renamed and prefixed with `spid_trentino_`; the expiry is stored as an ISO-8601 string.
- `SpidTrentino` is bound per request and receives the OIDC client by injection; `redirectToLogin()` returns a `RedirectResponse` and `handleCallback()` returns the `SpidTrentinoUser`.
- Login failures redirect to `error_redirect_to` with a flash message instead of throwing.
- Events expose a readonly `$user`.

### Removed
- `laravel/framework` and `firebase/php-jwt` requirements.
- `SpidAuthenticatesUsers::spidLogout()` (use `SpidTrentino::logout()`).
- The `spid-laravel-trentino.auth` container binding.
- The unused `AacLoginButton.vue` component and the StyleCI configuration.

### Fixed
- `spid.valid` read the SPID user from the wrong session key and logged every user out.
- `redirect_to` was read from the wrong config key and never applied; the config publish tag did not match the documentation.
- The facade resolved a binding that did not exist.
- Token expiry was never stored and the ID token was never validated, because the token response is an object, not an array; tokens were written to the session twice.
- `logout()` read a private property and failed; the controller logout did not dispatch `SpidTrentinoLoggedOut`.
- Callback failures were rethrown to the user.
- Token refresh read the wrong session keys, and a failed refresh left the user logged in without tokens.
- `MockOpenIDConnectClient` used claim names that do not match AAC and was incompatible with jumbojett 1.x.
- Events used `SerializesModels` on a plain DTO.
- Users could not be persisted without a migration; the default `users` table rejected users without email and password.
- The Blade login button linked to a fallback `/aac/login` and had a stray quote in its class attribute.
- The default `POST /logout` route collided with Breeze, Jetstream and Fortify.

### Security
- Tokens, the client secret and personal data are no longer written to logs.
- ID tokens without `exp`, or whose `aud` does not contain the client id, are rejected.
- A userinfo response without a fiscal code can no longer log the browser into an unrelated account.
- An email returned by SPID never takes over another local account.

## [1.0.0-dev] - 2025-06-17 (never tagged)

### Added
- First implementation (2025-06-08): SPID login through AAC Trentino with OpenID Connect and PKCE, session storage of user and tokens, login and logout events, `spid.valid` and `spid.refresh` middleware, configurable controller, Blade login button.

### Changed
- 2025-06-17: login handling moved from an event listener to `SpidAuthController`; README rewrite.

### Fixed
- 2025-06-17: claim mapping, configuration path, session persistence in the callback.
