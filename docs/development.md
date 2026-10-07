# Development

This page is for maintainers and contributors. Read [architecture](architecture.md) first for the class map, and [CONTRIBUTING.md](../CONTRIBUTING.md) for the contribution rules.

## Local setup

Requirements: PHP 8.4 or 8.5 with `curl`, `mbstring`, `openssl`, `pdo_sqlite`; Composer 2.

```bash
git clone https://github.com/offline-agency/spid-laravel-trentino.git
cd spid-laravel-trentino
composer update
```

`composer.lock` is not committed (this is a library), so `composer update` resolves the latest versions allowed by `composer.json`.

## Commands

| Command | What it runs |
|---------|--------------|
| `composer test` | `pest` |
| `composer test-coverage` | `pest --coverage --min=100` (fails below 100% line coverage) |
| `composer analyse` | `phpstan analyse` with Larastan at level max (`phpstan.neon`, no baseline) |
| `composer format` | `pint` (Laravel preset, `pint.json`) |
| `composer format-check` | `pint --test` |
| `composer mutate` | `pest --mutate` (mutation testing on the classes declared with `mutates()` in the tests) |

Coverage and mutation testing need a coverage driver (pcov or Xdebug). Without one, Pest prints `No code coverage driver is available.` Any of these works:

```bash
pecl install pcov
# or, with Xdebug installed but disabled:
php -d xdebug.mode=coverage vendor/bin/pest --coverage --min=100
```

## Test suite

`phpunit.xml` defines three suites; `tests/Pest.php` runs `Feature` and `Unit` on `OfflineAgency\SpidLaravelTrentino\Tests\TestCase`.

| Directory | Contents |
|-----------|----------|
| `tests/Arch` | Architecture rules: no debug helpers, strict types, controllers extend the base controller, no `$_SESSION`, no `Illuminate\Foundation` helpers in `src/` |
| `tests/Unit` | DTO, events, `SessionExpiry`, `TokenResponse`, `LogRedactor` |
| `tests/Feature` | Service provider, configuration, routes, OIDC client, service, controller, middleware, persistence, Blade component, mock client |
| `tests/Fixtures` | Test user models |
| `tests/Support/FakeAacProvider.php` | A fake AAC: discovery, JWKS, token and userinfo endpoints through `Http::fake()`, with real RS256-signed ID tokens from generated RSA keys |

`TestCase` (Orchestra Testbench) loads the provider and the facade alias, uses an in-memory SQLite database with `RefreshDatabase`, the array cache and session drivers, and test credentials (`test-client`, `https://aac.test`).

Testbench notes:

- Under Pest, Testbench does not call `defineDatabaseMigrations()`. The Laravel migrations come from the `WithLaravelMigrations` concern, and the package migration is registered on the migrator in `defineEnvironment()`, so `migrate:fresh` runs it.
- The helper `useCallbackRequest()` in `tests/Pest.php` sets the current request to a callback with the given query, for service-level tests.

Writing a callback test with the fake provider:

```php
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

it('logs the user in', function () {
    FakeAacProvider::fake();               // optionally override token or userinfo fields
    FakeAacProvider::startAuthorization(); // session state as left by spid.login

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE)->assertRedirect('/');

    $this->assertAuthenticated();
});
```

## MockOpenIDConnectClient

`OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient` ships with the package for application tests. It makes no HTTP calls:

| Method | Behavior |
|--------|----------|
| `authorizationRedirect()` | Redirect to `https://aac.mock.invalid/authorize` |
| `authenticateWith()` / `authenticate()` | Always succeed, access token `mock-access-token` |
| `getTokenResponse()` | Access, refresh and ID tokens (`MockOpenIDConnectClient::ACCESS_TOKEN`, `REFRESH_TOKEN`, `ID_TOKEN`), `expires_in` 3600 |
| `refreshToken()` | Access token `mock-refreshed-access-token`, same refresh token |
| `requestUserInfo()` | AAC-shaped claims, fiscal code `MockOpenIDConnectClient::FISCAL_CODE` (`TINIT-RSSMRA80A01H501U`) |
| `withUserInfo(array $claims)` | Replace top-level claims |

In an application test:

```php
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient;

it('logs in with SPID', function () {
    $this->app->instance(
        LaravelOpenIDConnectClient::class,
        (new MockOpenIDConnectClient)->withUserInfo(['given_name' => 'Giulia']),
    );

    $this->get('/spid/callback')->assertRedirect();

    $this->assertAuthenticated();
});
```

## Continuous integration

`.github/workflows/tests.yml` runs on pushes and pull requests to `master`:

| Job | What |
|-----|------|
| Tests | PHP 8.4 and 8.5 x Laravel 12 and 13 x `prefer-lowest` and `prefer-stable` (8 legs). Laravel 12 legs run Pest 4 (Testbench 10 conflicts with PHPUnit 13), Laravel 13 legs run Pest 5. |
| Coverage | PHP 8.5 with pcov, `--coverage --min=100`, upload to Codecov (`CODECOV_TOKEN` secret) |
| PHPStan | `composer analyse` |
| Pint | `pint --test` |
| Release scripts | `.github/scripts/next-version.test.sh` |

`.github/workflows/pr-title.yml` checks that pull request titles follow Conventional Commits. Dependabot (`.github/dependabot.yml`) opens weekly updates for Composer and GitHub Actions.

To reproduce one matrix leg locally:

```bash
composer require "laravel/framework:12.*" "orchestra/testbench:10.*" "pestphp/pest:4.*" --dev --no-update
composer update --prefer-lowest
vendor/bin/pest
```

Revert `composer.json` afterwards.

## Code style and quality gates

- PSR-12 through Laravel Pint; `declare(strict_types=1);` in every PHP file.
- PHPStan (Larastan) at level max, no baseline and no ignore comments.
- 100% line coverage; bug fixes start with a failing test.
- No `@codeCoverageIgnore`.

## Releasing

Releases are automatic (`.github/workflows/release.yml`). When a pull request is merged into `master` without the `skip-release` label:

1. The next version is computed by `.github/scripts/next-version.sh` from the latest `vX.Y.Z` tag and the pull request title:

   | Title or body | Bump |
   |---------------|------|
   | `<type>!: ...` or a `BREAKING CHANGE:` line in the body | major |
   | `feat: ...` | minor |
   | anything else | patch |

   Without tags the first version is `v2.0.0`.
2. An annotated tag is pushed on the merge commit (with `GITHUB_TOKEN`, which does not trigger other workflows).
3. A GitHub Release is created with generated notes.

Before merging a release-worthy pull request, move the `Unreleased` entries of `CHANGELOG.md` under the new version (the workflow does not edit files). Packagist picks up new tags through its GitHub webhook.
