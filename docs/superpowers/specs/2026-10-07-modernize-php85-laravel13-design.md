# Modernize and harden spid-laravel-trentino (2.0.0): design

Status: approved brief (see Appendix A), decisions confirmed on 2026-10-07.
Branch: `chore/modernize-php85-laravel13`.

## 1. Confirmed decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Supported matrix | PHP 8.4, 8.5 x Laravel 12, 13. Release as `2.0.0`. |
| 1b | Test framework | `pestphp/pest: ^4.0\|^5.0`. Laravel 13 legs run Pest 5 (PHPUnit 13); Laravel 12 legs run Pest 4 (PHPUnit 12) because every Testbench 10.x release conflicts with PHPUnit >= 13.1/13.2 and Pest 5 requires PHPUnit ^13.3.6. Coverage and mutation run on a Pest 5 leg. |
| 2 | Release versioning | Conventional Commits on the PR title (changed by the maintainer on 2026-10-07, replacing PR labels): `<type>!:` or a `BREAKING CHANGE` footer in the PR body means major, `feat:` minor, any other type patch (also the default for non-conforming titles). The `skip-release` label skips the release. A CI check validates PR titles. |
| 2b | Coverage goal | 100% line coverage enforced with `--min=100` (reaffirmed by the maintainer on 2026-10-07). |
| 3 | Coverage service | Codecov (`CODECOV_TOKEN` secret). |
| 4 | Security contact | `support@offlineagency.it` (from composer.json; to be confirmed by the maintainer). |
| 5 | AAC claim names | The DTO's `enti-*` claims are treated as the source of truth (`enti-codicefiscale.fiscalCode`, ...). Mocks are aligned to them. Open question until a real userinfo response is available. |
| 6 | Default branch | `master` (CI and release triggers target `master`, not `main`). |
| 7 | First release | No tags exist, so the release workflow starts at `v2.0.0`. The maintainer submits the package to Packagist. |
| 8 | Vue component | `resources/js/AacLoginButton.vue` is removed (never published or registered). |
| 9 | Commits | Conventional Commits, small, and no `Co-Authored-By` (or other co-authoring) trailers. No push and no PR without asking. |

## 2. Verified facts (2026-10-07)

- Packagist: `pestphp/pest` 5.3.0 (PHP ^8.4, PHPUnit ^13.3.6), 4.7.8 (PHP ^8.3, PHPUnit ^12.5);
  `orchestra/testbench` 11.3.0 (Laravel ^13), 10.12.0 (Laravel ^12);
  `jumbojett/openid-connect-php` 1.0.2; `firebase/php-jwt` 7.2.1; `larastan/larastan` 3.12.3 (PHPStan 2.3);
  `laravel/pint` 1.32.1; `pestphp/pest-plugin-mutate` ships with Pest 4 and 5.
- `firebase/php-jwt ^6.11` and `laravel/framework ^10|^11` are blocked by Composer security advisories: the current `composer.json` no longer installs.
- `offline-agency/spid-laravel-trentino` is not on Packagist (HTTP 404); the repository has no tags.
- Lowest resolvable Laravel 12 leg: `laravel/framework` 12.69.0, Testbench 10.2.0, Pest 4.3.2, PHPUnit 12.5.8 (older 12.x releases are blocked by advisories).

## 3. Design decisions from the code investigation

### 3.1 ID token validation lives in jumbojett, not in this package
`OpenIDConnectClient::authenticate()` (1.0.2) already:
- fetches `jwks_uri` from the discovery document and verifies the RS/PS/HS signature (`verifySignatures()`);
- verifies `iss`, `aud`, `sub`, `nonce` (when present), `exp` and `nbf` (300 s leeway), `at_hash` (`verifyJWTClaims()`).

The package therefore deletes its own `validateIdToken()` (which never ran, because the token response is a `stdClass`, and hardcoded `/.well-known/jwks.json`). Instead, `LaravelOpenIDConnectClient`:
- overrides `fetchURL()` to use the Laravel HTTP client (fakeable with `Http::fake()`), caching unauthenticated GETs (discovery and JWKS) for `cache_ttl` seconds and turning non-200 metadata responses and connection errors into `OpenIDConnectClientException`;
- on a signature verification exception (for example an unknown `kid` after key rotation) drops the cached JWKS once and retries;
- hardens `verifyJWTClaims()`: requires string `iss` and `sub`, requires `aud` to contain the client id (the parent throws a `TypeError` on a non-matching string `aud`), and requires an integer `exp` on ID tokens (the parent accepts tokens without `exp`).
`firebase/php-jwt` moves to `require-dev` (used only to sign test tokens).

### 3.2 No native session, no `exit`, no `$_SESSION`
`LaravelOpenIDConnectClient` overrides `startSession`, `commitSession` (no-ops), `getSessionKey`, `setSessionKey`, `unsetSessionKey` (Laravel session, keys prefixed with `SessionKeys::OIDC_PREFIX`), and `redirect()` (throws `HttpResponseException` with a `RedirectResponse`). The parent reads callback parameters from `$_REQUEST`; `authenticateWith(array $parameters)` swaps `$_REQUEST` with the whitelisted string parameters (`code`, `state`, `error`, `error_description`) for the duration of the parent call and restores it in `finally`. `authenticate()` passes the current Laravel request's parameters. `authorizationRedirect()` returns the redirect response used by the login route.

### 3.3 Container wiring
- `LaravelOpenIDConnectClient` is bound (new instance per resolution) from configuration in the service provider.
- `SpidTrentino` is bound `scoped` (one per request, Octane safe) and receives the client by constructor injection.
- The facade accessor is `SpidTrentino::class`.
- Middleware aliases `spid.valid` and `spid.refresh` are registered by the provider.
- Recommended middleware order is `spid.refresh` then `spid.valid` (the 1.x README showed the reverse, which logs users out before a refresh can happen).

### 3.4 Foundation-free source
`laravel/framework` is removed from `require`. Code in `src/` uses facades and contracts from `illuminate/*` packages instead of `Illuminate\Foundation` helpers (`config()`, `redirect()`, `response()`, `route()`, `url()`, `request()`, `app()`, `now()`, `config_path()`, `__()`) and the `Illuminate\Foundation\Events\Dispatchable` trait. An architecture test enforces this. Views and the config file run inside the host application and may use helpers.

### 3.5 Session keys
`SessionKeys` holds every key: `USER` (`spid_trentino_user`, unchanged), `ACCESS_TOKEN`, `REFRESH_TOKEN`, `ACCESS_TOKEN_EXPIRES_AT` (ISO-8601 string), `ERROR` (flash), `OIDC_PREFIX`. `Support\SessionExpiry` is the single reader/writer of the expiry and accepts legacy `Carbon` instances, strings and timestamps. An unparseable expiry counts as expired.

### 3.6 Failure handling and privacy
- Login and callback failures (`OpenIDConnectClientException`) are logged with the exception and redirect to `error_redirect_to` with a flash message under `SessionKeys::ERROR`.
- A callback whose userinfo lacks a fiscal code fails (otherwise `firstOrNew(['fiscal_code' => ''])` could match an unrelated account).
- Tokens, client secret and personal data are never logged; users are identified in logs by `Support\LogRedactor` (HMAC-SHA256 keyed with `app.key`, truncated).
- A token refresh that returns no access token (for example `invalid_grant`) throws; the refresh middleware then forgets the tokens so `spid.valid` ends the session.

### 3.7 Persistence
A publishable migration (`spid-laravel-trentino-migrations`) adds `fiscal_code` (nullable, unique), `surname`, `preferred_username`, `locale`, `zoneinfo`, `spid_profile` (json) and makes `email` and `password` nullable (SPID users have no local password and may have no email). `email` is mapped from the userinfo and only written when no other user owns it (no account linking by email).

### 3.8 Configuration (final keys)
`client_id`, `client_secret`, `redirect_uri` (null means the URL of the callback route), `provider_url`, `scopes`, `cache_ttl`, `register_routes`, `routes.login|callback|logout` (`/spid/login`, `/spid/callback`, `/spid/logout`), `auth_controller`, `redirect_to` (default null), `logout_redirect_to` (`/`), `error_redirect_to` (`/`). Publish tags: `spid-laravel-trentino-config`, `spid-laravel-trentino-views`, `spid-laravel-trentino-migrations`.

### 3.9 Release automation
`release.yml` runs on `pull_request: closed` to `master` only when merged and not labelled `skip-release`; `.github/scripts/next-version.sh` computes the next tag from the latest `vX.Y.Z` tag and the PR title/body (initial `v2.0.0`); a `pr-title.yml` workflow fails PRs whose title is not a Conventional Commit, the job creates an annotated tag pushed with `GITHUB_TOKEN` (which does not trigger other workflows) and `gh release create --verify-tag --generate-notes`. No commits are created. CHANGELOG updates are left to release notes (a follow-up PR flow is not reliable enough to automate safely).

---

## Appendix A: original brief (verbatim)

# Task: Modernize and harden `offline-agency/spid-laravel-trentino`

You are working on a Laravel package that integrates SPID authentication via AAC Trentino (OpenID Connect with PKCE). The package is funded by the Consorzio dei Comuni Trentini and released for reuse by other public administrations, so quality, security and documentation matter.

## Superpowers workflow (mandatory)

1. Start with `superpowers:brainstorming` only to confirm the open decisions listed under "Decisions to confirm". Ask me those questions in one batch, then stop brainstorming.
2. Use `superpowers:using-git-worktrees` and work on a dedicated branch (`chore/modernize-php85-laravel13`).
3. Use `superpowers:writing-plans` to produce a phased plan that covers every section below, then `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for independent phases).
4. Use `superpowers:test-driven-development` for every bug fix: write a failing test first, then the fix.
5. Use `superpowers:systematic-debugging` for any failure you can't explain right away.
6. Use `superpowers:verification-before-completion` before claiming any phase is done. Show the actual command output (tests, coverage, static analysis).
7. Finish with `superpowers:requesting-code-review` and then `superpowers:finishing-a-development-branch`.

## Git rules

- Do NOT add `Co-Authored-By` trailers (or any other co-authoring metadata) to any commit.
- Use small, meaningful commits following Conventional Commits (`fix:`, `feat:`, `chore:`, `docs:`, `test:`, `ci:`).
- Do not push or open a PR without asking me first.

## Decisions to confirm before starting

- Supported matrix. Proposal: PHP 8.3, 8.4, 8.5 and Laravel 12, 13 (Laravel 13 requires PHP >= 8.3). Dropping Laravel 10/11 is a breaking change, so release as `2.0.0`.
- Versioning strategy for automatic releases: PR labels (`release:major`, `release:minor`, `release:patch`, default patch) vs Conventional Commits on the PR title.
- Coverage reporting service for the badge (Codecov vs a self-generated badge committed to the repo or published on GitHub Pages).

## Phase 1: Dependency upgrade

- Before changing `composer.json`, verify on Packagist the latest stable versions and the PHP/Laravel constraints of: `pestphp/pest` (target Pest 5), `orchestra/testbench` (version matching Laravel 13), `jumbojett/openid-connect-php` (evaluate moving from `^0.9` to `^1.0`), `firebase/php-jwt`. If Pest 5 is not stable or conflicts with the matrix, stop and tell me.
- Remove the redundant `laravel/framework` requirement from `require`, keep only the needed `illuminate/*` components (and add `illuminate/http`, `illuminate/routing`, `illuminate/view` if used).
- Fix `autoload-dev` namespace (`OfflineAgency\SpidTrentino\Tests` vs the real `OfflineAgency\SpidLaravelTrentino` root) and make it consistent.
- Add `composer` scripts: `test`, `test-coverage` (Pest with `--coverage --min=100`), `analyse` (PHPStan / Larastan at the highest level you can reach without baselines), `format` (Laravel Pint, consistent with `.styleci.yml` laravel preset; remove StyleCI config if Pint replaces it).
- Add `.phpunit.result.cache`, `.phpunit.cache/`, `coverage/`, `build/` to `.gitignore` and remove the committed `.phpunit.result.cache`.
- Migrate `phpunit.xml` to the PHPUnit version required by Pest 5 (`<source>` instead of `<coverage><include>`, remove `./app`).

## Phase 2: Consistency and bug fixes

These are confirmed issues found in the current code. Fix each one with a failing test first.

**Config key mismatch**
- `SpidTrentinoServiceProvider` merges config as `spid-laravel-trentino` and publishes with tag `config` to `config/spid-laravel-trentino.php`, but the README documents `--tag=spid-config` and `config/spid.php`, and `SpidAuthenticatesUsers::redirectTo()` reads `config('spid.redirect_to')`, so `redirect_to` is never applied. Pick ONE key (`spid-laravel-trentino`) and one publish tag (`spid-laravel-trentino-config`), and align code, config comments and README.

**Facade broken**
- `SpidTrentinoFacade::getFacadeAccessor()` returns `spid-trentino`, but the container binds `spid-laravel-trentino.auth`. Align them (prefer binding `SpidTrentino::class` as singleton and returning the class name). Add `@method` docblocks to the facade.

**Session key mismatch (critical)**
- `EnsureValidSpidToken` checks `Session::get('spid_user')` but `SpidTrentino::handleCallback()` stores `spid_trentino_user`. Every request protected by `spid.valid` currently logs the user out. Introduce session key constants (e.g. a `SessionKeys` class) and use them everywhere.

**Logout**
- `SpidTrentino::logout()` reads `$user->fiscalNumber`, a non-existent property on a class with private properties. Use `getFiscalNumber()`.
- The user is read from session after building the DTO but before flush; keep that order and test that `SpidTrentinoLoggedOut` is dispatched.
- Decide whether the controller logout and `SpidTrentino::logout()` should be one path; remove the duplication (`SpidAuthenticatesUsers::spidLogout()` does not dispatch the event).

**Token response handling**
- `jumbojett` returns the token response as an object (`stdClass`), not an array. `extractExpiry()` and `validateIdToken()` both check `is_array()`, so expiry is never stored and the ID token is never validated. Normalize the response and test both paths.
- `handleCallback()` writes `refresh_token` and `access_token_expires_at` twice (once in `storeTokensInSession()`, then again directly). Keep only `storeTokensInSession()`.

**ID token validation**
- The JWKS URI is hardcoded to `/.well-known/jwks.json`. Read `jwks_uri` from the provider discovery document (`/.well-known/openid-configuration`), cache JWKS with a configurable TTL, and validate `iss`, `aud` (client_id), `exp`, and `nonce` when present. Evaluate whether `jumbojett` already verifies the ID token in `authenticate()` and avoid doing the same work twice; document the decision.

**Native PHP session and `exit()` in the OIDC library**
- `jumbojett/openid-connect-php` stores state, nonce and code verifier in `$_SESSION` and redirects with `header()` + `exit`. This bypasses the Laravel session, breaks in Octane/queues, and makes the login route untestable. Create a subclass (e.g. `LaravelOpenIDConnectClient`) that overrides the session methods (`startSession`, `commitSession`, `getSessionKey`, `setSessionKey`, `unsetSessionKey`) to use the Laravel session, and overrides `redirect()` to throw an `HttpResponseException` with a `RedirectResponse`. Update the `spid.login` route so it returns a proper response. Update the diagnostic logs that read `$_SESSION` in `SpidAuthController`.

**Dependency injection**
- `SpidTrentino::__construct()` does `new OpenIDConnectClient(...)` and `SpidAuthController::callback()` does `new SpidTrentino()`. Inject the client through the container (bind it in the service provider) and resolve `SpidTrentino` via injection, so tests can swap the client.

**Security and privacy of logs**
- `handleCallback()` logs the full access token, the refresh token and the user payload (fiscal code, names) at debug level. Never log tokens; log personal data only as hashed or redacted values. Add a test that asserts tokens never appear in log context.
- The callback currently rethrows `OpenIDConnectClientException` to the user. Decide a graceful failure path (redirect to a configurable error route with a flash message) and keep the detailed log.

**DTO and claims**
- `SpidTrentinoUser` reads `enti-codicefiscale`, while the test mocks and `MockOpenIDConnectClient` use `codicefiscale`. Verify the real claim names returned by AAC Trentino (check the AAC documentation / an actual userinfo response if I can provide one, otherwise ask me) and make the mocks match reality.
- `email` is requested in scopes but not mapped in the DTO. Map it.
- Events use `SerializesModels` on a non-model DTO; remove it or justify it.

**Middleware**
- Register `spid.valid` and `spid.refresh` aliases automatically in the service provider (`$router->aliasMiddleware`) so users don't need `Kernel.php` (which no longer exists in Laravel 11+).
- `EnsureValidSpidToken` compares `now()` with a session value that may be a serialized string; parse it the same way `RefreshSpidTokenIfNeeded` does (share a helper).

**Persistence**
- `SpidAuthenticatesUsers` expects `fiscal_code`, `surname`, `preferred_username`, `locale`, `zoneinfo`, `spid_profile` columns but the package ships no migration. Provide a publishable migration (tag `spid-laravel-trentino-migrations`) and document the required `$fillable` / `$casts` (`spid_profile` as `array`).
- Fix `getZoneInfo()` call vs `getZoneinfo()` method name (works because PHP methods are case-insensitive, but make it consistent).

**Routes and views**
- The default logout route `POST /logout` collides with most apps (Breeze, Jetstream, Fortify). Change the default to `/spid/logout` and document it. Make route registration optional via config (`register_routes`).
- `.env.example` uses `/aac/callback` while routes default to `/spid/callback`. Align.
- `login-button.blade.php` falls back to `/aac/login` and has a stray `"` inside the class string. Use `route('spid.login')`.
- The Vue component label says "Accedi con AAC"; align naming (SPID) across Blade and Vue, or remove the Vue component if it isn't published or used.
- `config_path()` publish key is under `runningInConsole()`, but views are never publishable; add a `spid-laravel-trentino-views` tag.

**Other**
- `CHANGELOG.md` has a placeholder entry; rewrite it with real history and the `2.0.0` entry (Keep a Changelog format).
- README "Security" email (`support@offlineagency.com`) differs from composer (`support@offlineagency.it`). Align to the correct one (ask me).
- README credits link points to `laravel-mongo-auto-sync` contributors. Fix it.

## Phase 3: Tests, 100% coverage

- Delete or rewrite `tests/Feature/AacAuthServiceTest.php`: it uses `Tests\TestCase` (does not exist in a package), expects `Auth::login` in `handleCallback()` (never called there), and binds a `user.resolver` that nothing uses.
- Create a proper `tests/TestCase.php` on Orchestra Testbench with the service provider loaded, an in-memory SQLite database, a test `User` model and the package migration.
- Use Pest 5 syntax and features (architecture tests: no `dd`/`dump`/`ray`, strict types in `src`, controllers extend the base controller, no direct `$_SESSION` access in `src`).
- Fake HTTP for discovery and JWKS with `Http::fake()`. Generate a real RSA key pair in tests to sign ID tokens and cover valid, expired, wrong `aud`, wrong `iss`, and bad signature cases.
- Cover: login redirect, callback success, callback failure, first login creating the user, subsequent login updating it, `redirectTo()` with config value, with `admin` role, and fallback; both middleware in all branches (missing session, expired token, JSON vs HTML request, refresh success, refresh failure); facade; DTO factories (`fromArray`, `fromJson` valid/invalid with and without throw, `fromStdClass`); events; Blade component rendering; config publishing.
- Target: 100% line coverage enforced with `--min=100`. Do not use `@codeCoverageIgnore` unless the code is genuinely unreachable in tests, and list every use in the final report with a justification.
- Add mutation testing with `pest --mutate` on the core classes if Pest 5 supports it in this setup, and report the score (no hard threshold).
- Run the full suite on every matrix combination locally if possible (or via CI) and show the results.

## Phase 4: CI and automatic releases

Replace `.github/workflows/test.yml`:

- Current issues: matrix includes PHP 8.1 while composer requires `^8.2`; it spins up an unused MySQL service; it doesn't test multiple Laravel versions.
- New `tests.yml`: on push and pull_request to `main`; matrix PHP 8.3/8.4/8.5 x Laravel 12/13 x `prefer-lowest`/`prefer-stable`, with the correct Testbench mapping and exclusions for unsupported combinations; `pcov` for coverage on one job only; upload coverage to the chosen service; run Pint (check mode) and PHPStan as separate jobs; use `concurrency` to cancel superseded runs; pin actions to major versions.
- New `release.yml`: triggered on `pull_request` `closed` against `main`, running only when `github.event.pull_request.merged == true` (closed-without-merge PRs must not release). It must:
  - compute the next SemVer tag from the latest existing tag and the agreed strategy (labels or Conventional Commit PR title), defaulting to patch;
  - skip the release when the PR has a `skip-release` label;
  - create the annotated tag and a GitHub Release with auto-generated notes (`gh release create --generate-notes` or an equivalent maintained action);
  - use `permissions: contents: write` only on that job;
  - not create commits authored by a bot that could retrigger workflows;
  - optionally update `CHANGELOG.md` via a follow-up PR (not a direct push to `main`), only if simple to do reliably; otherwise rely on release notes and tell me.
- Packagist updates via its GitHub webhook; document in README how to verify the hook is active (do not add Packagist API tokens to the workflow unless I ask).
- Add `.github/dependabot.yml` for `composer` and `github-actions`.
- Add PR template and issue templates (bug, feature) in English.

## Phase 5: README rewrite

Rewrite `README.md` in English, keep it accurate to the final code (every snippet must work as written):

- Badges row: Packagist latest version, total downloads, PHP version support (from Packagist), Laravel version support, tests workflow status, coverage, PHPStan level, license. Use the correct repository and package URLs.
- Short description, then a "Funding and reuse" section stating that the package was developed with funding from the [Consorzio dei Comuni Trentini](https://www.comunitrentini.it/) and is released as open source software for reuse by public administrations and other parties (in line with art. 69 of the Italian CAD, Codice dell'Amministrazione Digitale). Verify the Consorzio URL is reachable before committing.
- Requirements table (PHP, Laravel, supported matrix).
- Installation, publishing config / migration / views (with the real tags), `.env` variables, AAC client registration notes (redirect URI must match the callback route).
- Configuration reference for every config key.
- Routes table, middleware usage (auto-registered aliases, Laravel 11+ `bootstrap/app.php` example if manual registration is still useful).
- User model requirements (columns, `$fillable`, casts) and how to customize authentication by extending the controller.
- Session keys reference (from the constants class), events, token refresh behavior.
- Testing section: how to run tests, coverage, static analysis; how to use `MockOpenIDConnectClient` in the consuming app.
- Release process (automatic tag and release on merged PR, labels).
- Security policy (move to `SECURITY.md`), contributing (update `CONTRIBUTING.md`: PSR-12 / Pint instead of PSR-2), credits, license.
- No em dashes anywhere in the documentation; use commas or parentheses.

## Optional: reuse metadata

Create a `publiccode.yml` skeleton (Developers Italia standard) so the package can be listed in the Italian reuse catalogue. Fill in only fields you can derive from the repo; mark everything else with clear `TODO` comments and tell me which fields need my input. Validate it with the official `publiccode-parser` if available.

## Definition of done

- `composer test-coverage` reports 100% line coverage; `composer analyse` and Pint pass; full CI matrix green.
- Every bug listed in Phase 2 has a regression test that failed before the fix.
- README snippets verified against the code.
- Final report: summary of changes per phase, breaking changes for the `2.0.0` upgrade guide (also add `UPGRADE.md`), any `@codeCoverageIgnore` with justification, open questions.
- No commit contains `Co-Authored-By` trailers.
