# spid-laravel-trentino 2.0.0 Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `offline-agency/spid-laravel-trentino` 2.0.0. It targets PHP 8.4/8.5 and Laravel 12/13, fixes every confirmed bug with a regression test, reaches 100% line coverage, and adds CI, automatic releases and accurate documentation.

**Architecture:** A `LaravelOpenIDConnectClient` subclass of `jumbojett/openid-connect-php` 1.x keeps the OIDC state in the Laravel session, throws redirects instead of calling `exit`, and routes HTTP through the Laravel HTTP client with cached discovery and JWKS. ID token verification stays in the library, with hardened claim checks. The client is injected into a request-scoped `SpidTrentino` service used by the controller, the facade and the middleware. Source code depends only on `illuminate/*` components, never on `Illuminate\Foundation`.

**Tech Stack:** PHP 8.4/8.5, Laravel 12/13 components, jumbojett/openid-connect-php ^1.0.2, Pest 4 (Laravel 12) / Pest 5 (Laravel 13), Orchestra Testbench 10/11, Larastan 3 / PHPStan 2, Laravel Pint, GitHub Actions, Codecov.

**Spec:** `docs/superpowers/specs/2026-10-07-modernize-php85-laravel13-design.md` (Appendix A is the maintainer's original brief).

## Global Constraints

- PHP `^8.4`; Laravel components `^12.0|^13.0` (floors tightened in Task 14 to what `--prefer-lowest` actually installs and passes).
- Dev: `pestphp/pest ^4.3|^5.0`, `orchestra/testbench ^10.2|^11.0`, `larastan/larastan ^3.12`, `laravel/pint ^1.32`, `firebase/php-jwt ^7.2` (dev only).
- No `laravel/framework` in `require`; `src/` must not use `Illuminate\Foundation` classes or helpers (`config()`, `redirect()`, `response()`, `route()`, `url()`, `request()`, `app()`, `now()`, `session()`, `config_path()`, `__()`).
- Config key `spid-laravel-trentino`; publish tags `spid-laravel-trentino-config`, `spid-laravel-trentino-views`, `spid-laravel-trentino-migrations`.
- Default routes: `GET /spid/login` (`spid.login`), `GET /spid/callback` (`spid.callback`), `POST /spid/logout` (`spid.logout`).
- Every PHP file starts with `declare(strict_types=1);`. Code style: Pint `laravel` preset.
- Never log tokens, the client secret or personal data; identify users in logs only through `LogRedactor`.
- Every Phase 2 bug fix starts with a failing test that is run and seen failing before the fix.
- Conventional Commits; small commits; **no `Co-Authored-By` or other co-authoring trailers**; no push and no PR without asking the maintainer.
- Default branch is `master`. Release versioning uses the Conventional Commit PR title; the `skip-release` label skips the release; the first tag is `v2.0.0`.
- Documentation: English, no em dashes (use commas or parentheses).
- Security contact: `support@offlineagency.it`.
- No `@codeCoverageIgnore` unless the code is genuinely unreachable; every use is listed in the final report.

## Review Focus

1. **Userinfo without a fiscal code.** If AAC answers without `enti-codicefiscale.fiscalCode`, the callback must fail with the error redirect, not log the browser into whichever local account has an empty or null fiscal code. Test in Task 7 and Task 8.
2. **AAC signing-key rotation while JWKS is cached.** A cached JWKS that lacks the new `kid` must trigger one cache refresh and retry, not lock everyone out for `cache_ttl` seconds. Test in Task 5.
3. **Refresh token rejected (`invalid_grant`) or AAC unreachable during refresh.** The current request continues, the tokens are forgotten, and with the documented middleware order (`spid.refresh`, `spid.valid`) the user is sent back to `spid.login` instead of silently staying logged in without tokens. Test in Task 9.
4. **SPID email already used by another local user.** The login succeeds without a unique-constraint error and without taking over the other account's email (no linking by email). Test in Task 11.
5. **AAC down or returning non-JSON discovery at login, or malformed callback query (`?code[]=x`).** The user gets the configured error redirect (login) or a fresh authorization redirect (callback), never a 500 or a `TypeError`. Tests in Task 5 and Task 6.

---

## File structure

```
composer.json                      deps, autoload-dev namespace, scripts
phpunit.xml                        PHPUnit 12/13 compatible (<source>)
phpstan.neon                       Larastan, level max, no baseline
pint.json                          Pint laravel preset (replaces .styleci.yml)
.gitattributes                     export-ignore dev files
config/spid-laravel-trentino.php   (renamed from config/config.php) all config keys
database/migrations/add_spid_trentino_columns_to_users_table.php
routes/spid-trentino-auth.php      login, callback, logout -> controller
resources/views/components/login-button.blade.php
src/
  SessionKeys.php                  every session key constant
  SpidTrentino.php                 service: login redirect, callback, refresh, logout, userinfo
  SpidTrentinoFacade.php           accessor SpidTrentino::class + @method docs
  SpidTrentinoServiceProvider.php  config, client binding, scoped service, aliases, routes, publish tags
  SpidTrentinoUser.php             DTO (+ email, type-tolerant hydration)
  OpenIdConnect/LaravelOpenIDConnectClient.php
  Support/SessionExpiry.php        read/write/compare access-token expiry
  Support/TokenResponse.php        normalize jumbojett token responses (object|array)
  Support/LogRedactor.php          keyed hashes for log context
  Http/Controllers/SpidAuthController.php   login, callback, logout
  Http/Middleware/EnsureValidSpidToken.php
  Http/Middleware/RefreshSpidTokenIfNeeded.php
  Traits/SpidAuthenticatesUsers.php authenticateFromSpid, spidLoginFailed, redirectTo
  Events/SpidTrentinoLoggedIn.php, Events/SpidTrentinoLoggedOut.php
  Testing/MockOpenIDConnectClient.php
tests/
  Pest.php, TestCase.php
  Fixtures/User.php, Fixtures/AdminUser.php, Fixtures/NotAuthenticatable.php
  Support/FakeAacProvider.php      RSA keys, signed ID tokens, Http::fake for AAC
  Arch/ArchTest.php
  Unit/{SessionExpiry,TokenResponse,LogRedactor,SpidTrentinoUser,Events}Test.php
  Feature/{ServiceProvider,Configuration,RedirectTo,LaravelOpenIDConnectClient,SpidTrentinoService,
           AuthController,EnsureValidSpidToken,RefreshSpidTokenIfNeeded,AuthenticateFromSpid,
           MockOpenIDConnectClient,LoginButton}Test.php
.github/workflows/{tests,release,pr-title}.yml
.github/scripts/{next-version.sh,next-version.test.sh,check-pr-title.sh}
.github/dependabot.yml, .github/PULL_REQUEST_TEMPLATE.md, .github/ISSUE_TEMPLATE/{bug_report.yml,feature_request.yml,config.yml}
README.md, CHANGELOG.md, UPGRADE.md, SECURITY.md, CONTRIBUTING.md, publiccode.yml, .env.example
```

Removed: `.styleci.yml`, `.phpunit.result.cache`, `config/config.php` (renamed), `resources/js/AacLoginButton.vue`, `tests/Feature/AacAuthServiceTest.php`, `.github/workflows/test.yml`.

---

# Phase 1: Dependency upgrade and harness

### Task 1: Toolchain, composer.json and Testbench harness

**Files:**
- Modify: `composer.json`, `phpunit.xml`, `.gitignore`, `tests/Pest.php`
- Create: `phpstan.neon`, `pint.json`, `.gitattributes`, `tests/TestCase.php`, `tests/Fixtures/User.php`, `tests/Feature/ServiceProviderTest.php`
- Delete: `.styleci.yml`, `.phpunit.result.cache`, `tests/Feature/AacAuthServiceTest.php`

**Interfaces:**
- Produces: `OfflineAgency\SpidLaravelTrentino\Tests\TestCase` (Testbench, `RefreshDatabase`, provider + alias loaded, sqlite memory, array cache and session, config for client `test-client`/`test-secret`/`https://aac.test`); `OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User`; composer scripts `test`, `test-coverage`, `analyse`, `format`, `format-check`, `mutate`.

- [ ] **Step 1: Re-verify Packagist versions (5 min, read-only)**

Run:
```bash
for p in pestphp/pest orchestra/testbench jumbojett/openid-connect-php firebase/php-jwt larastan/larastan laravel/pint; do curl -s https://repo.packagist.org/p2/$p.json | php -r '$d=json_decode(stream_get_contents(STDIN),true);$v=array_values($d["packages"])[0][0];echo $v["name"]," ",$v["version"],"\n";'; done
```
Expected: pest v5.3.x, testbench v11.x, jumbojett v1.0.2, php-jwt v7.2.x, larastan v3.12.x, pint v1.32.x. If a new major appeared, stop and report.

- [ ] **Step 2: Replace `composer.json`**

```json
{
    "name": "offline-agency/spid-laravel-trentino",
    "description": "SPID authentication for Laravel through AAC Trentino (OpenID Connect with PKCE)",
    "keywords": ["laravel", "spid", "aac", "trentino", "openid-connect", "oidc", "pkce", "pubblica-amministrazione"],
    "homepage": "https://github.com/offline-agency/spid-laravel-trentino",
    "license": "MIT",
    "type": "library",
    "authors": [
        {
            "name": "Offline Agency",
            "email": "support@offlineagency.it",
            "homepage": "https://offlineagency.it",
            "role": "Developer"
        }
    ],
    "support": {
        "issues": "https://github.com/offline-agency/spid-laravel-trentino/issues",
        "source": "https://github.com/offline-agency/spid-laravel-trentino",
        "security": "https://github.com/offline-agency/spid-laravel-trentino/security/policy"
    },
    "require": {
        "php": "^8.4",
        "illuminate/auth": "^12.0|^13.0",
        "illuminate/cache": "^12.0|^13.0",
        "illuminate/config": "^12.0|^13.0",
        "illuminate/contracts": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "illuminate/events": "^12.0|^13.0",
        "illuminate/http": "^12.0|^13.0",
        "illuminate/log": "^12.0|^13.0",
        "illuminate/routing": "^12.0|^13.0",
        "illuminate/session": "^12.0|^13.0",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/view": "^12.0|^13.0",
        "jumbojett/openid-connect-php": "^1.0.2"
    },
    "require-dev": {
        "firebase/php-jwt": "^7.2",
        "larastan/larastan": "^3.12",
        "laravel/pint": "^1.32",
        "orchestra/testbench": "^10.2|^11.0",
        "pestphp/pest": "^4.3|^5.0"
    },
    "autoload": {
        "psr-4": {
            "OfflineAgency\\SpidLaravelTrentino\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "OfflineAgency\\SpidLaravelTrentino\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "pest",
        "test-coverage": "pest --coverage --min=100",
        "mutate": "pest --mutate",
        "analyse": "phpstan analyse --memory-limit=1G",
        "format": "pint",
        "format-check": "pint --test"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "OfflineAgency\\SpidLaravelTrentino\\SpidTrentinoServiceProvider"
            ],
            "aliases": {
                "SpidTrentino": "OfflineAgency\\SpidLaravelTrentino\\SpidTrentinoFacade"
            }
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 3: Install**

Run: `composer update --no-interaction`
Expected: ends with `No security vulnerability advisories found.`; `composer show pestphp/pest` shows 5.x and `laravel/framework` 13.x.

- [ ] **Step 4: Replace `phpunit.xml`** (valid for PHPUnit 12 and 13)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
>
    <testsuites>
        <testsuite name="Arch">
            <directory suffix="Test.php">tests/Arch</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
    </php>
</phpunit>
```

- [ ] **Step 5: Tooling config files**

`phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - src
        - routes
        - database
    tmpDir: build/phpstan
```

`pint.json`:
```json
{
    "preset": "laravel"
}
```

`.gitattributes`:
```
/.github            export-ignore
/docs               export-ignore
/tests              export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/phpunit.xml        export-ignore
/phpstan.neon       export-ignore
/pint.json          export-ignore
/publiccode.yml     export-ignore
```

`.gitignore`:
```
/vendor
/.idea
composer.lock
.phpunit.result.cache
.phpunit.cache/
/coverage
/build
.DS_Store
```

Then:
```bash
git rm -q .styleci.yml .phpunit.result.cache tests/Feature/AacAuthServiceTest.php
git ls-files | grep -i ds_store | xargs -r git rm -q --cached
```

- [ ] **Step 6: Write the harness**

`tests/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoFacade;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [SpidTrentinoServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SpidTrentino' => SpidTrentinoFacade::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('auth.providers.users.model', User::class);

        $config->set('spid-laravel-trentino.client_id', 'test-client');
        $config->set('spid-laravel-trentino.client_secret', 'test-secret');
        $config->set('spid-laravel-trentino.provider_url', 'https://aac.test');
        $config->set('spid-laravel-trentino.redirect_uri', 'https://app.test/spid/callback');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }
}
```

`tests/Fixtures/User.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'fiscal_code',
        'surname',
        'preferred_username',
        'locale',
        'zoneinfo',
    ];

    protected function casts(): array
    {
        return ['spid_profile' => 'array'];
    }
}
```

`tests/Pest.php` (replace the whole file):
```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
```

`tests/Feature/ServiceProviderTest.php` (smoke test; extended in Task 6):
```php
<?php

declare(strict_types=1);

it('merges the package configuration', function () {
    expect(config('spid-laravel-trentino.provider_url'))->toBe('https://aac.test')
        ->and(config('spid-laravel-trentino.scopes'))->toBeString();
});
```

- [ ] **Step 7: Run the harness**

Run: `composer test`
Expected: `Tests: 1 passed`.

- [ ] **Step 8: Coverage driver**

Run: `php -m | grep -iE '^(pcov|xdebug)$'`. If neither is present, try `pecl install pcov` and re-check. If `pecl` is unavailable for Herd's PHP, use Docker for coverage runs from now on:
```bash
docker run --rm -v "$PWD":/app -w /app php:8.5-cli sh -c \
  'apt-get update -qq && apt-get install -yqq git unzip libsqlite3-dev >/dev/null && pecl install pcov >/dev/null && docker-php-ext-enable pcov && curl -sS https://getcomposer.org/installer | php -- --quiet && php composer.phar install -q && vendor/bin/pest --coverage'
```
Record the command that works in the report notes. Expected: a coverage table is printed (the percentage is low at this point).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore(deps): require PHP 8.4+, Laravel 12/13 components and jumbojett 1.x" -m "Drop laravel/framework from require, move firebase/php-jwt to require-dev, add Pest 4/5, Testbench 10/11, Larastan and Pint, fix the autoload-dev namespace and add composer scripts."
```
Check: `git log -1 --format=%B | grep -i co-authored` prints nothing.

---

### Task 2: Pint reformat, strict types and architecture tests

**Files:**
- Modify: every PHP file in `src/`, `config/`, `routes/` (Pint, `declare(strict_types=1);`)
- Create: `tests/Arch/ArchTest.php`

- [ ] **Step 1: Reformat**

Run: `composer format` then `composer format-check`
Expected: second command reports `PASS`.

- [ ] **Step 2: Commit the formatting alone**

```bash
git add -A && git commit -m "style: apply Laravel Pint (replaces StyleCI)"
```

- [ ] **Step 3: Write the architecture tests**

`tests/Arch/ArchTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Controller;

arch('no debugging helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'print_r'])
    ->not->toBeUsed();

arch('source files declare strict types')
    ->expect('OfflineAgency\SpidLaravelTrentino')
    ->toUseStrictTypes()
    ->ignoring('OfflineAgency\SpidLaravelTrentino\Tests');

arch('controllers extend the base routing controller')
    ->expect('OfflineAgency\SpidLaravelTrentino\Http\Controllers')
    ->toExtend(Controller::class);

test('src never touches $_SESSION directly', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

    foreach ($files as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), '$_SESSION')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
```

- [ ] **Step 4: Run and see the strict-types test fail**

Run: `vendor/bin/pest tests/Arch`
Expected: FAIL on `source files declare strict types` (for example `SpidTrentino.php`, `SpidTrentinoUser.php`).

- [ ] **Step 5: Add `declare(strict_types=1);`** right after `<?php` in every PHP file under `src/`, `config/` and `routes/` that lacks it.

- [ ] **Step 6: Run the tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add tests/Arch/ArchTest.php && git commit -m "test: add architecture tests"
git add src config routes && git commit -m "chore: declare strict types in every source file"
```

---

# Phase 2: Consistency and bug fixes (each fix test-first)

### Task 3: Session keys, shared expiry helper and `EnsureValidSpidToken`

**Files:**
- Create: `src/SessionKeys.php`, `src/Support/SessionExpiry.php`, `tests/Unit/SessionExpiryTest.php`, `tests/Feature/EnsureValidSpidTokenTest.php`
- Modify: `src/Http/Middleware/EnsureValidSpidToken.php`

**Interfaces:**
- Produces: `SessionKeys::USER = 'spid_trentino_user'`, `ACCESS_TOKEN = 'spid_trentino_access_token'`, `REFRESH_TOKEN = 'spid_trentino_refresh_token'`, `ACCESS_TOKEN_EXPIRES_AT = 'spid_trentino_access_token_expires_at'`, `ERROR = 'spid_trentino_error'`, `OIDC_PREFIX = 'spid_trentino_oidc_'`.
- Produces: `SessionExpiry::put(?DateTimeInterface): void`, `get(): ?CarbonImmutable`, `hasExpired(): bool`, `expiresWithin(int $seconds): bool`.

- [ ] **Step 1: Write the failing middleware test**

`tests/Feature/EnsureValidSpidTokenTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;

beforeEach(function () {
    Route::middleware(['web', EnsureValidSpidToken::class])->get('/protected', fn () => 'ok');
    $this->freezeTime();
});

function validSpidSession(array $overrides = []): array
{
    return array_merge([
        SessionKeys::USER => ['sub' => 'subject-123', 'enti-codicefiscale' => ['fiscalCode' => 'TINIT-RSSMRA80A01H501U']],
        SessionKeys::ACCESS_TOKEN => 'access-token',
        SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->addHour()->toIso8601String(),
    ], $overrides);
}

it('lets the request through when the session written at login is valid', function () {
    $this->withSession(validSpidSession())->get('/protected')->assertOk()->assertSee('ok');
});

it('lets the request through when no expiry is known', function () {
    $session = validSpidSession();
    unset($session[SessionKeys::ACCESS_TOKEN_EXPIRES_AT]);

    $this->withSession($session)->get('/protected')->assertOk();
});

it('redirects to the SPID login and ends the session when it is invalid', function (array $session) {
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'mario@example.com', 'password' => 'secret']);

    $this->actingAs($user)->withSession($session)->get('/protected')->assertRedirect('/spid/login');

    $this->assertGuest();
    expect(session()->has(SessionKeys::USER))->toBeFalse();
})->with([
    'no SPID user' => [array_diff_key(validSpidSession(), [SessionKeys::USER => true])],
    'no access token' => [array_diff_key(validSpidSession(), [SessionKeys::ACCESS_TOKEN => true])],
    // A closure is resolved inside the test (after freezeTime) and its return value is the single argument.
    'expired (ISO string)' => fn () => validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->subMinute()->toIso8601String()]),
    'expired (legacy Carbon instance)' => fn () => validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => Carbon::now()->subMinute()]),
    'unparseable expiry' => [validSpidSession([SessionKeys::ACCESS_TOKEN_EXPIRES_AT => 'not-a-date'])],
]);

it('answers 419 JSON to API clients when the session is invalid', function () {
    $this->getJson('/protected')
        ->assertStatus(419)
        ->assertExactJson(['message' => 'SPID session missing or expired.']);
});
```

- [ ] **Step 2: Run it and see it fail**

Run: `vendor/bin/pest tests/Feature/EnsureValidSpidTokenTest.php`
Expected: FAIL. `Class "OfflineAgency\SpidLaravelTrentino\SessionKeys" not found`. After Step 3 creates the class, the first test still fails with a redirect to `/spid/login` instead of 200, because the middleware reads `spid_user`. Re-run after Step 3 to confirm that is the failure.

- [ ] **Step 3: Create `src/SessionKeys.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

/**
 * Every session key written by the package.
 */
final class SessionKeys
{
    /** Array payload of the SPID user (SpidTrentinoUser::toArray()). */
    public const string USER = 'spid_trentino_user';

    public const string ACCESS_TOKEN = 'spid_trentino_access_token';

    public const string REFRESH_TOKEN = 'spid_trentino_refresh_token';

    /** ISO-8601 expiry of the access token (read it with Support\SessionExpiry). */
    public const string ACCESS_TOKEN_EXPIRES_AT = 'spid_trentino_access_token_expires_at';

    /** Flash message set when the SPID login fails. */
    public const string ERROR = 'spid_trentino_error';

    /** Prefix of the OIDC state, nonce and PKCE verifier kept during the login round trip. */
    public const string OIDC_PREFIX = 'spid_trentino_oidc_';
}
```

Run Step 2's command again. Expected: FAIL on `lets the request through when the session written at login is valid` (redirect instead of 200).

- [ ] **Step 4: Write the failing expiry unit test**

`tests/Unit/SessionExpiryTest.php`:
```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00')));

it('stores the expiry as an ISO-8601 string and forgets it when null', function () {
    SessionExpiry::put(CarbonImmutable::now()->addHour());
    expect(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00');

    SessionExpiry::put(null);
    expect(Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();
});

it('reads every format older releases stored', function (mixed $raw) {
    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, $raw);

    expect(SessionExpiry::get()?->toIso8601String())->toBe('2026-01-01T13:00:00+00:00');
})->with([
    'ISO string' => '2026-01-01T13:00:00+00:00',
    'Carbon' => fn () => now()->addHour(),
    'DateTimeImmutable' => fn () => new DateTimeImmutable('2026-01-01T13:00:00+00:00'),
    'unix timestamp' => 1767272400,
]);

it('returns null for missing or unparseable values', function (mixed $raw) {
    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, $raw);

    expect(SessionExpiry::get())->toBeNull();
})->with(['empty string' => '', 'garbage' => 'not-a-date', 'array' => [[1]], 'null' => null]);

it('knows when the token has expired', function () {
    expect(SessionExpiry::hasExpired())->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSecond());
    expect(SessionExpiry::hasExpired())->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now());
    expect(SessionExpiry::hasExpired())->toBeTrue();

    Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, 'not-a-date');
    expect(SessionExpiry::hasExpired())->toBeTrue();
});

it('knows when the token expires within a window', function () {
    expect(SessionExpiry::expiresWithin(60))->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSeconds(61));
    expect(SessionExpiry::expiresWithin(60))->toBeFalse();

    SessionExpiry::put(CarbonImmutable::now()->addSeconds(60));
    expect(SessionExpiry::expiresWithin(60))->toBeTrue();
});
```

Run: `vendor/bin/pest tests/Unit/SessionExpiryTest.php`
Expected: FAIL with `Class "OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry" not found`.

- [ ] **Step 5: Create `src/Support/SessionExpiry.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;

/**
 * Single reader and writer of the access-token expiry kept in the session.
 * Accepts the formats 1.x stored (Carbon instances, strings, timestamps).
 */
final class SessionExpiry
{
    public static function put(?DateTimeInterface $expiresAt): void
    {
        if ($expiresAt === null) {
            Session::forget(SessionKeys::ACCESS_TOKEN_EXPIRES_AT);

            return;
        }

        Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, CarbonImmutable::instance($expiresAt)->toIso8601String());
    }

    public static function get(): ?CarbonImmutable
    {
        $raw = Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT);

        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        if (is_int($raw)) {
            return CarbonImmutable::createFromTimestamp($raw);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * True when a stored expiry has passed or cannot be parsed.
     */
    public static function hasExpired(): bool
    {
        if (! Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT)) {
            return false;
        }

        $expiresAt = self::get();

        return $expiresAt === null || $expiresAt->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public static function expiresWithin(int $seconds): bool
    {
        $expiresAt = self::get();

        return $expiresAt !== null && $expiresAt->subSeconds($seconds)->lessThanOrEqualTo(CarbonImmutable::now());
    }
}
```

Run: `vendor/bin/pest tests/Unit/SessionExpiryTest.php`
Expected: PASS. If `createFromTimestamp` yields a non-UTC zone and the timestamp case fails, set `->setTimezone('UTC')` in the test expectation, not in the code.

- [ ] **Step 6: Rewrite `src/Http/Middleware/EnsureValidSpidToken.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session when the SPID user or access token is missing, or when the
 * access token has expired. HTML requests are redirected to the SPID login;
 * JSON requests get 419 so the client can re-authenticate.
 */
class EnsureValidSpidToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Session::has(SessionKeys::USER) && Session::has(SessionKeys::ACCESS_TOKEN) && ! SessionExpiry::hasExpired()) {
            return $next($request);
        }

        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        if ($request->expectsJson()) {
            return ResponseFactory::json(['message' => 'SPID session missing or expired.'], 419);
        }

        return Redirect::guest(URL::route('spid.login'));
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/pest tests/Feature/EnsureValidSpidTokenTest.php tests/Unit/SessionExpiryTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/SessionKeys.php src/Support/SessionExpiry.php tests/Unit/SessionExpiryTest.php
git commit -m "feat: add SessionKeys constants and a shared SessionExpiry helper"
git add src/Http/Middleware/EnsureValidSpidToken.php tests/Feature/EnsureValidSpidTokenTest.php
git commit -m "fix: read the SPID user from the key written at login in spid.valid" -m "EnsureValidSpidToken looked for 'spid_user' while the callback stores 'spid_trentino_user', so every protected request logged the user out. It now also requires an access token, parses the expiry with SessionExpiry and invalidates the session."
```

---

### Task 4: One config key, publish tags and `redirect_to`

**Files:**
- Rename: `config/config.php` to `config/spid-laravel-trentino.php` (full content below)
- Modify: `src/SpidTrentinoServiceProvider.php` (merge path, publish tags), `src/Traits/SpidAuthenticatesUsers.php` (`redirectTo()`)
- Create: `tests/Feature/ConfigurationTest.php`, `tests/Feature/RedirectToTest.php`, `tests/Fixtures/AdminUser.php`

**Interfaces:**
- Produces: config keys listed in spec 3.8 (later tasks rely on `cache_ttl`, `register_routes`, `routes.*`, `redirect_to`, `logout_redirect_to`, `error_redirect_to`).

- [ ] **Step 1: Write the failing tests**

`tests/Fixtures/AdminUser.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

class AdminUser extends User
{
    public function hasRole(string $role): bool
    {
        return $role === 'admin';
    }
}
```

`tests/Feature/RedirectToTest.php`:
```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\AdminUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

function postLoginTarget(): string
{
    return (new class
    {
        use SpidAuthenticatesUsers;

        public function target(): string
        {
            return $this->redirectTo();
        }
    })->target();
}

it('uses the configured redirect_to path', function () {
    config()->set('spid-laravel-trentino.redirect_to', '/area-riservata');

    expect(postLoginTarget())->toBe('/area-riservata');
});

it('sends admins to the admin dashboard when redirect_to is not set', function () {
    $this->actingAs(new AdminUser(['name' => 'Admin']));

    expect(postLoginTarget())->toBe('/admin/dashboard');
});

it('falls back to the home page', function (mixed $configured) {
    config()->set('spid-laravel-trentino.redirect_to', $configured);
    $this->actingAs(new User(['name' => 'Mario']));

    expect(postLoginTarget())->toBe('/');
})->with(['null' => null, 'empty string' => '']);
```

`tests/Feature/ConfigurationTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;

it('publishes the configuration under the spid-laravel-trentino-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/spid-laravel-trentino.php')
        ->and(array_values($paths)[0])->toBe(config_path('spid-laravel-trentino.php'));
});

it('publishes the views under the spid-laravel-trentino-views tag', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-views');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('resources/views')
        ->and(array_values($paths)[0])->toBe(resource_path('views/vendor/spid-laravel-trentino'));
});

it('ships secure, non-colliding defaults', function () {
    expect(config('spid-laravel-trentino.routes'))->toBe([
        'login' => '/spid/login',
        'callback' => '/spid/callback',
        'logout' => '/spid/logout',
    ])
        ->and(config('spid-laravel-trentino.register_routes'))->toBeTrue()
        ->and(config('spid-laravel-trentino.redirect_to'))->toBeNull()
        ->and(config('spid-laravel-trentino.logout_redirect_to'))->toBe('/')
        ->and(config('spid-laravel-trentino.error_redirect_to'))->toBe('/')
        ->and(config('spid-laravel-trentino.cache_ttl'))->toBe(3600);
});
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/RedirectToTest.php tests/Feature/ConfigurationTest.php`
Expected: FAIL. `uses the configured redirect_to path` returns `/` (the trait reads `spid.redirect_to`), the publish tags are empty, and the defaults differ (`/logout`, `/dashboard`, missing keys).

- [ ] **Step 3: Write `config/spid-laravel-trentino.php`** (then `git rm config/config.php`)

```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;

/*
|--------------------------------------------------------------------------
| SPID Laravel Trentino
|--------------------------------------------------------------------------
| Publish with:
|   php artisan vendor:publish --tag=spid-laravel-trentino-config
*/

return [

    /*
    | OIDC client credentials issued by AAC Trentino.
    */
    'client_id' => env('SPID_TRENTINO_CLIENT_ID'),
    'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),

    /*
    | Redirect URI registered on AAC. It must match the callback route exactly.
    | When null, the absolute URL of routes.callback is used.
    */
    'redirect_uri' => env('SPID_TRENTINO_REDIRECT_URI'),

    /*
    | AAC base URL. The discovery document is read from
    | {provider_url}/.well-known/openid-configuration.
    | The default is the AAC test environment: override it in production.
    */
    'provider_url' => env('SPID_TRENTINO_PROVIDER_URL', 'https://aac-test.cloud-test.tndigit.it'),

    /*
    | Space separated scopes ("openid" is always added).
    */
    'scopes' => env('SPID_TRENTINO_SCOPES', 'openid profile.codicefiscale.me email offline_access'),

    /*
    | Seconds the discovery document and the JWKS are cached (0 disables caching).
    */
    'cache_ttl' => 3600,

    /*
    | Set to false to register your own routes named spid.login, spid.callback
    | and spid.logout.
    */
    'register_routes' => true,

    'routes' => [
        'login' => '/spid/login',
        'callback' => '/spid/callback',
        'logout' => '/spid/logout',
    ],

    /*
    | Controller handling login, callback and logout. Extend SpidAuthController
    | to customize how users are created or authenticated.
    */
    'auth_controller' => SpidAuthController::class,

    /*
    | Path used after a successful login when there is no intended URL.
    | null uses SpidAuthenticatesUsers::redirectTo() (admins to /admin/dashboard, others to /).
    */
    'redirect_to' => env('SPID_TRENTINO_REDIRECT_TO'),

    /*
    | Path used after logout.
    */
    'logout_redirect_to' => '/',

    /*
    | Path used when the SPID login fails. The message is flashed to the
    | session under OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR.
    */
    'error_redirect_to' => '/',
];
```

- [ ] **Step 4: Fix the provider's merge path and publish tags**

In `src/SpidTrentinoServiceProvider.php` change the merge to `__DIR__.'/../config/spid-laravel-trentino.php'` and replace the `runningInConsole()` block with:
```php
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spid-laravel-trentino.php' => $this->app->configPath('spid-laravel-trentino.php'),
            ], 'spid-laravel-trentino-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/spid-laravel-trentino'),
            ], 'spid-laravel-trentino-views');
        }
```
(Task 6 rewrites the whole provider and keeps this block.)

- [ ] **Step 5: Fix `redirectTo()` in `src/Traits/SpidAuthenticatesUsers.php`**

Add `use Illuminate\Support\Facades\Config;` and replace the method:
```php
    protected function redirectTo(): string
    {
        $configured = Config::get('spid-laravel-trentino.redirect_to');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $user = Auth::user();

        if ($user !== null && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return '/admin/dashboard';
        }

        return '/';
    }
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/pest tests/Feature/RedirectToTest.php tests/Feature/ConfigurationTest.php`
Expected: PASS. Then `composer test`: all pass.

- [ ] **Step 7: Commit**

```bash
git add -A config src/SpidTrentinoServiceProvider.php tests/Feature/ConfigurationTest.php
git commit -m "fix: publish config under spid-laravel-trentino-config and make views publishable"
git add src/Traits/SpidAuthenticatesUsers.php tests/Feature/RedirectToTest.php tests/Fixtures/AdminUser.php
git commit -m "fix: read redirect_to from the spid-laravel-trentino config key"
```

---

### Task 5: `LaravelOpenIDConnectClient` (Laravel session, no exit, cached HTTP, hardened claims)

**Files:**
- Create: `src/OpenIdConnect/LaravelOpenIDConnectClient.php`, `tests/Support/FakeAacProvider.php`, `tests/Feature/LaravelOpenIDConnectClientTest.php`

**Interfaces:**
- Produces: `new LaravelOpenIDConnectClient(string $providerUrl, string $clientId, ?string $clientSecret = null, int $cacheTtl = 3600)`; `authenticate(): bool` (current request); `authenticateWith(array $parameters): bool`; `authorizationRedirect(): Illuminate\Http\RedirectResponse`; `redirect(string $url): never`; `getResponseContentType(): ?string`; inherited `getTokenResponse()`, `requestUserInfo()`, `refreshToken(string)`, `setAccessToken(string)`, `getClientID()`.
- Produces (tests): `FakeAacProvider` constants `ISSUER`, `CLIENT_ID`, `CLIENT_SECRET`, `KEY_ID`, `STATE`, `NONCE`, `CODE_VERIFIER`, `ACCESS_TOKEN`, `REFRESH_TOKEN`, `FISCAL_CODE`; methods `discoveryUrl()`, `discovery()`, `jwks(string $key = 'trusted')`, `idToken(array $overrides = [], string $signingKey = 'trusted')`, `tokenResponse(array $overrides = [])`, `userInfo(array $overrides = [])`, `fake(array $token = [], array $userInfo = [])`, `startAuthorization()`.

- [ ] **Step 1: Write the fake provider**

`tests/Support/FakeAacProvider.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OpenSSLAsymmetricKey;

/**
 * Stand-in for AAC Trentino: discovery, JWKS, token and userinfo endpoints
 * served through Http::fake(), with real RS256-signed ID tokens.
 */
final class FakeAacProvider
{
    public const string ISSUER = 'https://aac.test';

    public const string CLIENT_ID = 'test-client';

    public const string CLIENT_SECRET = 'test-secret';

    public const string KEY_ID = 'test-key';

    public const string STATE = 'state-123';

    public const string NONCE = 'nonce-123';

    public const string CODE_VERIFIER = 'verifier-123';

    public const string ACCESS_TOKEN = 'test-access-token-value';

    public const string REFRESH_TOKEN = 'test-refresh-token-value';

    public const string FISCAL_CODE = 'TINIT-RSSMRA80A01H501U';

    /** @var array<string, OpenSSLAsymmetricKey> */
    private static array $keys = [];

    public static function discoveryUrl(): string
    {
        return self::ISSUER.'/.well-known/openid-configuration';
    }

    /** @return array<string, mixed> */
    public static function discovery(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwk',
            'end_session_endpoint' => self::ISSUER.'/endsession',
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
    }

    /** @return array{keys: list<array<string, string>>} */
    public static function jwks(string $key = 'trusted'): array
    {
        $details = openssl_pkey_get_details(self::key($key));

        return ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];
    }

    /**
     * @param  array<string, mixed>  $overrides  claims to change; null removes a claim
     */
    public static function idToken(array $overrides = [], string $signingKey = 'trusted'): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-123',
            'iat' => time(),
            'exp' => time() + 600,
            'nonce' => self::NONCE,
        ], $overrides);

        openssl_pkey_export(self::key($signingKey), $pem);

        return JWT::encode(array_filter($claims, fn (mixed $value): bool => $value !== null), $pem, 'RS256', self::KEY_ID);
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes a field
     * @return array<string, mixed>
     */
    public static function tokenResponse(array $overrides = []): array
    {
        return array_filter(array_merge([
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => self::idToken(),
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * Userinfo shaped like AAC Trentino's (enti-* claims).
     *
     * @param  array<string, mixed>  $overrides  null removes a claim
     * @return array<string, mixed>
     */
    public static function userInfo(array $overrides = []): array
    {
        return array_filter(array_merge([
            'sub' => 'subject-123',
            'given_name' => 'Mario',
            'family_name' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'preferred_username' => 'mario.rossi',
            'locale' => 'it',
            'zoneinfo' => 'Europe/Rome',
            'realm' => 'test-realm',
            'id' => 'user-123',
            'enti-codicefiscale' => ['fiscalCode' => self::FISCAL_CODE, 'id' => 'cf-1'],
            'enti-spid' => ['isSpid' => 'true', 'spidCode' => 'TEST0000000001', 'id' => 'spid-1'],
            'enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'acr-1'],
            'enti-issuersource' => ['issuerSource' => 'https://idp.test', 'id' => 'issuer-1'],
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $token  token endpoint overrides (see tokenResponse())
     * @param  array<string, mixed>  $userInfo  userinfo overrides (see userInfo())
     */
    public static function fake(array $token = [], array $userInfo = []): void
    {
        Http::fake([
            self::discoveryUrl() => Http::response(self::discovery()),
            self::ISSUER.'/jwk' => Http::response(self::jwks()),
            self::ISSUER.'/oauth/token' => Http::response(self::tokenResponse($token)),
            self::ISSUER.'/userinfo*' => Http::response(self::userInfo($userInfo)),
        ]);
    }

    /**
     * Puts the session in the state the login route leaves it in.
     */
    public static function startAuthorization(): void
    {
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_state', self::STATE);
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_nonce', self::NONCE);
        Session::put(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier', self::CODE_VERIFIER);
    }

    private static function key(string $name): OpenSSLAsymmetricKey
    {
        return self::$keys[$name] ??= openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: Write the failing client tests**

`tests/Feature/LaravelOpenIDConnectClientTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

function oidcClient(int $cacheTtl = 3600): LaravelOpenIDConnectClient
{
    $client = new LaravelOpenIDConnectClient(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID, FakeAacProvider::CLIENT_SECRET, $cacheTtl);
    $client->setRedirectURL('https://app.test/spid/callback');
    $client->setCodeChallengeMethod('S256');

    return $client;
}

/** @return array<string, string> */
function callbackParameters(): array
{
    return ['code' => 'auth-code', 'state' => FakeAacProvider::STATE];
}

it('builds the authorization redirect and keeps state, nonce and PKCE verifier in the Laravel session', function () {
    FakeAacProvider::fake();

    $response = oidcClient()->authorizationRedirect();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toStartWith(FakeAacProvider::ISSUER.'/oauth/authorize?');

    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
    $verifier = Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier');

    expect($query['state'])->toBe(Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_state'))
        ->and($query['nonce'])->toBe(Session::get(SessionKeys::OIDC_PREFIX.'openid_connect_nonce'))
        ->and($query['client_id'])->toBe(FakeAacProvider::CLIENT_ID)
        ->and($query['redirect_uri'])->toBe('https://app.test/spid/callback')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and(session_status())->toBe(PHP_SESSION_NONE)
        ->and($_SESSION ?? [])->toBe([]);
});

it('fails when the authorization request does not end in a redirect', function () {
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function authenticateWith(array $parameters): bool
        {
            return true;
        }
    };

    expect(fn () => $client->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'The authorization request did not produce a redirect.');
});

it('turns redirects into HttpResponseException instead of calling exit', function () {
    try {
        oidcClient()->redirect('https://aac.test/somewhere');
        $this->fail('redirect() returned');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->headers->get('Location'))->toBe('https://aac.test/somewhere');
    }
});

it('completes the code flow with a valid signed ID token', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    $client = oidcClient();

    expect($client->authenticateWith(callbackParameters()))->toBeTrue()
        ->and($client->getAccessToken())->toBe(FakeAacProvider::ACCESS_TOKEN)
        ->and($client->getVerifiedClaims('sub'))->toBe('subject-123')
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_state'))->toBeFalse()
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_nonce'))->toBeFalse()
        ->and(Session::has(SessionKeys::OIDC_PREFIX.'openid_connect_code_verifier'))->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/oauth/token'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === FakeAacProvider::CODE_VERIFIER
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret')));
});

it('reads the callback parameters from the current Laravel request', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    app()->instance('request', Request::create('/spid/callback', 'GET', callbackParameters()));
    Facade::clearResolvedInstance('request');

    expect(oidcClient()->authenticate())->toBeTrue();
});

it('restores the $_REQUEST superglobal after authenticating', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();
    $_REQUEST = ['untouched' => 'yes'];

    oidcClient()->authenticateWith(callbackParameters());

    expect($_REQUEST)->toBe(['untouched' => 'yes']);
    $_REQUEST = [];
});

it('rejects ID tokens that fail verification', function (array $claims, string $signingKey) {
    FakeAacProvider::fake(['id_token' => FakeAacProvider::idToken($claims, $signingKey)]);
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient()->authenticateWith(callbackParameters()))
        ->toThrow(OpenIDConnectClientException::class);
})->with([
    'expired' => [['exp' => time() - 3600], 'trusted'],
    'missing exp' => [['exp' => null], 'trusted'],
    'wrong aud (string)' => [['aud' => 'another-client'], 'trusted'],
    'wrong aud (array)' => [['aud' => ['another-client', 'third-client']], 'trusted'],
    'missing aud' => [['aud' => null], 'trusted'],
    'wrong iss' => [['iss' => 'https://evil.test'], 'trusted'],
    'missing iss' => [['iss' => null], 'trusted'],
    'missing sub' => [['sub' => null], 'trusted'],
    'nonce mismatch' => [['nonce' => 'other-nonce'], 'trusted'],
    'bad signature' => [[], 'attacker'],
]);

it('accepts an aud array that contains the client id', function () {
    FakeAacProvider::fake(['id_token' => FakeAacProvider::idToken(['aud' => ['other', FakeAacProvider::CLIENT_ID]])]);
    FakeAacProvider::startAuthorization();

    expect(oidcClient()->authenticateWith(callbackParameters()))->toBeTrue();
});

it('rejects a state mismatch', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient()->authenticateWith(['code' => 'auth-code', 'state' => 'forged']))
        ->toThrow(OpenIDConnectClientException::class, 'Unable to determine state');
});

it('surfaces errors returned by AAC on the callback', function () {
    expect(fn () => oidcClient()->authenticateWith(['error' => 'access_denied', 'error_description' => 'User cancelled']))
        ->toThrow(OpenIDConnectClientException::class, 'Error: access_denied Description: User cancelled');
});

it('ignores non-string callback parameters and restarts the authorization', function () {
    FakeAacProvider::fake();

    expect(fn () => oidcClient()->authenticateWith(['code' => ['auth-code'], 'state' => FakeAacProvider::STATE]))
        ->toThrow(HttpResponseException::class);
});

it('caches the discovery document and the JWKS', function () {
    FakeAacProvider::fake();

    FakeAacProvider::startAuthorization();
    oidcClient()->authenticateWith(callbackParameters());
    FakeAacProvider::startAuthorization();
    oidcClient()->authenticateWith(callbackParameters());

    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::discoveryUrl()))->toHaveCount(1)
        ->and(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('does not cache when cache_ttl is 0', function () {
    FakeAacProvider::fake();

    oidcClient(0)->authorizationRedirect();
    oidcClient(0)->authorizationRedirect();

    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::discoveryUrl()))->toHaveCount(2);
});

it('refreshes a cached JWKS once when AAC rotated its signing key', function () {
    FakeAacProvider::fake();
    Cache::put('spid-laravel-trentino:oidc:'.sha1(FakeAacProvider::ISSUER.'/jwk'), json_encode(['keys' => []]), 3600);
    FakeAacProvider::startAuthorization();

    expect(oidcClient()->authenticateWith(callbackParameters()))->toBeTrue()
        ->and(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('does not retry signature verification when nothing was cached', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(['keys' => []]),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(FakeAacProvider::tokenResponse()),
    ]);
    FakeAacProvider::startAuthorization();

    expect(fn () => oidcClient(0)->authenticateWith(callbackParameters()))->toThrow(OpenIDConnectClientException::class);
    expect(Http::recorded(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/jwk'))->toHaveCount(1);
});

it('reports AAC metadata errors as OpenIDConnectClientException and does not cache them', function () {
    Http::fake([FakeAacProvider::discoveryUrl() => Http::sequence()
        ->push('<html>maintenance</html>', 503, ['Content-Type' => 'text/html'])
        ->push(FakeAacProvider::discovery())]);

    expect(fn () => oidcClient()->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'AAC returned HTTP 503 for '.FakeAacProvider::discoveryUrl());
    expect(oidcClient()->authorizationRedirect())->toBeInstanceOf(RedirectResponse::class);
});

it('reports connection failures as OpenIDConnectClientException', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => oidcClient()->authorizationRedirect())
        ->toThrow(OpenIDConnectClientException::class, 'Unable to reach AAC: Connection refused');
});

it('sends JSON bodies as JSON and exposes the response content type', function () {
    Http::fake(['https://aac.test/register' => Http::response(['client_id' => 'x'], 201)]);
    $client = new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function post(string $url, string $body): string
        {
            return $this->fetchURL($url, $body, ['Accept: application/json']);
        }
    };

    expect($client->post('https://aac.test/register', '{"a":1}'))->toBe('{"client_id":"x"}')
        ->and($client->getResponseCode())->toBe(201)
        ->and($client->getResponseContentType())->toBe('application/json');

    Http::assertSent(fn ($request) => $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Accept', 'application/json'));
});

it('never starts or commits a native PHP session', function () {
    $client = oidcClient();

    (fn () => $this->startSession())->call($client);
    (fn () => $this->commitSession())->call($client);

    expect(session_status())->toBe(PHP_SESSION_NONE);
});
```

- [ ] **Step 3: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/LaravelOpenIDConnectClientTest.php`
Expected: FAIL with `Class "OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient" not found`.

- [ ] **Step 4: Implement the client**

`src/OpenIdConnect/LaravelOpenIDConnectClient.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\OpenIdConnect;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;

/**
 * jumbojett/openid-connect-php adapted to Laravel.
 *
 * - State, nonce and PKCE verifier are kept in the Laravel session, never in $_SESSION.
 * - Redirects throw an HttpResponseException instead of calling header() and exit.
 * - HTTP goes through the Laravel HTTP client; the discovery document and the
 *   JWKS are cached for $cacheTtl seconds.
 *
 * The ID token signature (keys from the discovered jwks_uri) and the iss, aud,
 * sub, nonce, exp, nbf and at_hash claims are verified by the parent inside
 * authenticate(). This class only adds the checks the parent misses (string
 * iss/sub, aud membership without a TypeError, mandatory exp) and does not
 * validate the token a second time.
 */
class LaravelOpenIDConnectClient extends OpenIDConnectClient
{
    /** Callback parameters the parent reads from $_REQUEST. */
    private const array CALLBACK_PARAMETERS = ['code', 'state', 'error', 'error_description'];

    private const string CACHE_PREFIX = 'spid-laravel-trentino:oidc:';

    private ?string $lastContentType = null;

    public function __construct(
        string $providerUrl,
        string $clientId,
        ?string $clientSecret = null,
        private readonly int $cacheTtl = 3600,
    ) {
        parent::__construct($providerUrl, $clientId, $clientSecret);
    }

    /**
     * Runs the code flow against the parameters of the current request.
     *
     * @throws OpenIDConnectClientException
     */
    public function authenticate(): bool
    {
        return $this->authenticateWith(Request::only(self::CALLBACK_PARAMETERS));
    }

    /**
     * Runs the code flow against the given callback parameters. The parent
     * reads $_REQUEST, so it is swapped for the whitelisted string parameters
     * and restored afterwards.
     *
     * @param  array<array-key, mixed>  $parameters
     *
     * @throws OpenIDConnectClientException
     */
    public function authenticateWith(array $parameters): bool
    {
        $globalRequest = $_REQUEST;
        $_REQUEST = array_filter(
            array_intersect_key($parameters, array_flip(self::CALLBACK_PARAMETERS)),
            is_string(...),
        );

        try {
            $authenticated = parent::authenticate();
        } finally {
            $_REQUEST = $globalRequest;
        }

        $this->unsetCodeVerifier();

        return $authenticated;
    }

    /**
     * Builds the redirect to the AAC authorization endpoint.
     *
     * @throws OpenIDConnectClientException
     */
    public function authorizationRedirect(): RedirectResponse
    {
        try {
            $this->authenticateWith([]);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();

            if ($response instanceof RedirectResponse) {
                return $response;
            }
        }

        throw new OpenIDConnectClientException('The authorization request did not produce a redirect.');
    }

    public function redirect(string $url): never
    {
        throw new HttpResponseException(new RedirectResponse($url));
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function verifyJWTSignature(string $jwt): bool
    {
        try {
            return parent::verifyJWTSignature($jwt);
        } catch (OpenIDConnectClientException $exception) {
            // AAC may have rotated its keys since the JWKS was cached.
            if (! $this->forgetCachedJwks()) {
                throw $exception;
            }

            return parent::verifyJWTSignature($jwt);
        }
    }

    public function getResponseContentType(): ?string
    {
        return $this->lastContentType;
    }

    /**
     * @param  mixed  $claims
     */
    protected function verifyJWTClaims($claims, ?string $accessToken = null): bool
    {
        if (! is_object($claims)
            || ! isset($claims->iss, $claims->sub)
            || ! is_string($claims->iss)
            || ! is_string($claims->sub)) {
            return false;
        }

        $audiences = isset($claims->aud) && is_array($claims->aud) ? $claims->aud : [$claims->aud ?? null];

        if (! in_array($this->getClientID(), $audiences, true)) {
            return false;
        }

        // OIDC Core 3.1.3.7: ID tokens must carry exp. The parent accepts tokens without it.
        if ($accessToken !== null && ! (isset($claims->exp) && is_int($claims->exp))) {
            return false;
        }

        return parent::verifyJWTClaims($claims, $accessToken);
    }

    protected function startSession(): void
    {
        // The Laravel session is started by the StartSession middleware.
    }

    protected function commitSession(): void
    {
        // The Laravel session is saved by the StartSession middleware.
    }

    protected function getSessionKey(string $key): mixed
    {
        return Session::get(SessionKeys::OIDC_PREFIX.$key, false);
    }

    protected function setSessionKey(string $key, mixed $value): void
    {
        Session::put(SessionKeys::OIDC_PREFIX.$key, $value);
    }

    protected function unsetSessionKey(string $key): void
    {
        Session::forget(SessionKeys::OIDC_PREFIX.$key);
    }

    /**
     * Unauthenticated GETs (discovery document and JWKS) are cached and must
     * answer 200; other requests are returned as-is for the parent to inspect.
     *
     * @param  array<int, string>  $headers  "Name: value" lines
     *
     * @throws OpenIDConnectClientException
     */
    protected function fetchURL(string $url, ?string $post_body = null, array $headers = []): string
    {
        $isMetadata = $post_body === null && $headers === [];
        $cacheKey = self::CACHE_PREFIX.sha1($url);

        if ($isMetadata && $this->cacheTtl > 0) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached)) {
                $this->responseCode = 200;
                $this->lastContentType = 'application/json';

                return $cached;
            }
        }

        $body = $this->send($url, $post_body, $headers);

        if ($isMetadata && $this->responseCode !== 200) {
            throw new OpenIDConnectClientException("AAC returned HTTP {$this->responseCode} for {$url}");
        }

        if ($isMetadata && $this->cacheTtl > 0) {
            Cache::put($cacheKey, $body, $this->cacheTtl);
        }

        return $body;
    }

    /**
     * @param  array<int, string>  $headers
     *
     * @throws OpenIDConnectClientException
     */
    private function send(string $url, ?string $body, array $headers): string
    {
        $request = Http::withUserAgent($this->getUserAgent())
            ->timeout($this->getTimeout())
            ->withHeaders($this->parseHeaders($headers));

        try {
            $response = $body === null
                ? $request->get($url)
                : $request->withBody($body, $this->contentTypeFor($body))->post($url);
        } catch (ConnectionException $exception) {
            throw new OpenIDConnectClientException('Unable to reach AAC: '.$exception->getMessage(), 0, $exception);
        }

        $this->responseCode = $response->status();
        $this->lastContentType = $response->header('Content-Type') ?: null;

        return $response->body();
    }

    private function forgetCachedJwks(): bool
    {
        $jwksUri = $this->getProviderConfigValue('jwks_uri');

        return is_string($jwksUri) && Cache::forget(self::CACHE_PREFIX.sha1($jwksUri));
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    private function parseHeaders(array $headers): array
    {
        $parsed = [];

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $parsed[trim($name)] = trim($value);
        }

        return $parsed;
    }

    private function contentTypeFor(string $body): string
    {
        return is_object(json_decode($body)) ? 'application/json' : 'application/x-www-form-urlencoded';
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/LaravelOpenIDConnectClientTest.php`
Expected: PASS. If a case fails for a reason not explained by the test's intent (for example `getKeyForHeader` requiring a JWK field the fake omits, or Http fake URL matching), use superpowers:systematic-debugging. Read the parent method in `vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php` before changing code.

- [ ] **Step 6: Commit**

```bash
git add tests/Support/FakeAacProvider.php
git commit -m "test: add a fake AAC provider with RSA-signed ID tokens"
git add src/OpenIdConnect tests/Feature/LaravelOpenIDConnectClientTest.php
git commit -m "feat: add LaravelOpenIDConnectClient on top of jumbojett 1.x" -m "Keeps OIDC state in the Laravel session, throws redirects instead of exit, uses the Laravel HTTP client with cached discovery and JWKS (cache_ttl), refreshes a stale JWKS once on key rotation, and rejects ID tokens without exp, without the client in aud, or with non-string iss/sub."
```

---

### Task 6: Container wiring, facade, middleware aliases, routes and the login route

**Files:**
- Modify: `src/SpidTrentinoServiceProvider.php` (rewrite), `src/SpidTrentinoFacade.php` (rewrite), `src/SpidTrentino.php` (constructor + `redirectToLogin()`), `routes/spid-trentino-auth.php` (rewrite), `src/Http/Controllers/SpidAuthController.php` (add `login()`), `src/Traits/SpidAuthenticatesUsers.php` (add `spidLoginFailed()`), `.env.example`
- Modify: `tests/Feature/ServiceProviderTest.php`; Create: `tests/Feature/AuthControllerTest.php`

**Interfaces:**
- Consumes: `LaravelOpenIDConnectClient` (Task 5), config keys (Task 4), `SessionKeys::ERROR`.
- Produces: container binding `LaravelOpenIDConnectClient::class` (new instance per resolve) and `scoped(SpidTrentino::class)`; `SpidTrentino::__construct(LaravelOpenIDConnectClient $oidc)`, `SpidTrentino::redirectToLogin(): Illuminate\Http\RedirectResponse`; route `spid.login` to `SpidAuthController@login`; trait method `spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/ServiceProviderTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoFacade;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;

it('merges the package configuration', function () {
    expect(config('spid-laravel-trentino.provider_url'))->toBe('https://aac.test')
        ->and(config('spid-laravel-trentino.scopes'))->toBeString();
});

it('resolves the facade and its alias to the SpidTrentino service', function () {
    expect(SpidTrentinoFacade::getFacadeRoot())->toBeInstanceOf(SpidTrentino::class)
        ->and(\SpidTrentino::getFacadeRoot())->toBe(SpidTrentinoFacade::getFacadeRoot());
});

it('keeps one SpidTrentino instance per request', function () {
    $first = app(SpidTrentino::class);

    expect(app(SpidTrentino::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(SpidTrentino::class))->not->toBe($first);
});

it('builds the OIDC client from configuration', function () {
    $client = app(LaravelOpenIDConnectClient::class);

    expect($client->getProviderURL())->toBe('https://aac.test')
        ->and($client->getClientID())->toBe('test-client')
        ->and($client->getClientSecret())->toBe('test-secret')
        ->and($client->getRedirectURL())->toBe('https://app.test/spid/callback')
        ->and($client->getScopes())->toBe(['profile.codicefiscale.me', 'email', 'offline_access'])
        ->and($client->getCodeChallengeMethod())->toBe('S256')
        ->and(app(LaravelOpenIDConnectClient::class))->not->toBe($client);
});

it('defaults the redirect URI to the callback route and treats an empty secret as public client', function () {
    config()->set('spid-laravel-trentino.redirect_uri', null);
    config()->set('spid-laravel-trentino.client_secret', '');

    $client = app(LaravelOpenIDConnectClient::class);

    expect($client->getRedirectURL())->toBe(url('/spid/callback'))
        ->and($client->getClientSecret())->toBeNull();
});

it('falls back to the default cache TTL when cache_ttl is not numeric', function () {
    config()->set('spid-laravel-trentino.cache_ttl', 'forever');

    expect((fn () => $this->cacheTtl)->call(app(LaravelOpenIDConnectClient::class)))->toBe(3600);
});

it('registers the spid.valid and spid.refresh middleware aliases', function () {
    expect(app('router')->getMiddleware())
        ->toMatchArray([
            'spid.valid' => EnsureValidSpidToken::class,
            'spid.refresh' => RefreshSpidTokenIfNeeded::class,
        ]);
});

it('registers login, callback and logout routes', function () {
    expect(route('spid.login', absolute: false))->toBe('/spid/login')
        ->and(route('spid.callback', absolute: false))->toBe('/spid/callback')
        ->and(route('spid.logout', absolute: false))->toBe('/spid/logout')
        ->and(Route::getRoutes()->getByName('spid.logout')->methods())->toBe(['POST']);
});

it('skips route registration when register_routes is false', function (bool $register) {
    config()->set('spid-laravel-trentino.register_routes', $register);
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    (new SpidTrentinoServiceProvider(app()))->boot($router);

    expect(Route::has('spid.login'))->toBe($register);
})->with(['disabled' => false, 'enabled' => true]);
```

`tests/Feature/AuthControllerTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

it('redirects the login route to the AAC authorization endpoint', function () {
    FakeAacProvider::fake();

    $response = $this->get('/spid/login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(FakeAacProvider::ISSUER.'/oauth/authorize?')
        ->and(session()->has(SessionKeys::OIDC_PREFIX.'openid_connect_state'))->toBeTrue();
});

it('sends the user to the error page when AAC cannot be reached at login', function () {
    Http::fake([FakeAacProvider::discoveryUrl() => Http::response('down', 503)]);
    config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed');

    $this->get('/spid/login')
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
});
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php tests/Feature/AuthControllerTest.php`
Expected: FAIL. The facade can't resolve `spid-trentino`, the client binding and aliases are missing, the logout route is `/logout`, and `/spid/login` returns 500 (the old closure builds a cURL client against `https://aac.test`).

- [ ] **Step 3: Rewrite the provider**

`src/SpidTrentinoServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

class SpidTrentinoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spid-laravel-trentino.php', 'spid-laravel-trentino');

        $this->app->bind(LaravelOpenIDConnectClient::class, fn (): LaravelOpenIDConnectClient => $this->makeClient());
        $this->app->scoped(SpidTrentino::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('spid.valid', EnsureValidSpidToken::class);
        $router->aliasMiddleware('spid.refresh', RefreshSpidTokenIfNeeded::class);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spid-laravel-trentino');

        if (Config::boolean('spid-laravel-trentino.register_routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/spid-trentino-auth.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spid-laravel-trentino.php' => $this->app->configPath('spid-laravel-trentino.php'),
            ], 'spid-laravel-trentino-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/spid-laravel-trentino'),
            ], 'spid-laravel-trentino-views');
        }
    }

    private function makeClient(): LaravelOpenIDConnectClient
    {
        $secret = Config::get('spid-laravel-trentino.client_secret');
        $cacheTtl = Config::get('spid-laravel-trentino.cache_ttl');
        $redirectUri = Config::get('spid-laravel-trentino.redirect_uri');
        $scopes = preg_split('/\s+/', Config::string('spid-laravel-trentino.scopes'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $client = new LaravelOpenIDConnectClient(
            Config::string('spid-laravel-trentino.provider_url'),
            Config::string('spid-laravel-trentino.client_id'),
            is_string($secret) && $secret !== '' ? $secret : null,
            is_numeric($cacheTtl) ? (int) $cacheTtl : 3600,
        );

        $client->setRedirectURL(is_string($redirectUri) && $redirectUri !== ''
            ? $redirectUri
            : URL::to(Config::string('spid-laravel-trentino.routes.callback')));
        // "openid" is always added by the client.
        $client->addScope(array_values(array_diff($scopes, ['openid'])));
        $client->setCodeChallengeMethod('S256');

        return $client;
    }
}
```

- [ ] **Step 4: Rewrite the facade**

`src/SpidTrentinoFacade.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RedirectResponse redirectToLogin()
 * @method static SpidTrentinoUser handleCallback()
 * @method static void refreshAccessToken()
 * @method static void logout()
 * @method static object|null getUserInfo()
 *
 * @see SpidTrentino
 */
class SpidTrentinoFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SpidTrentino::class;
    }
}
```

- [ ] **Step 5: Inject the client into `SpidTrentino` and return the login redirect**

In `src/SpidTrentino.php` replace the property, the constructor (including its five `Log::info()` lines that printed the client secret) and `redirectToLogin()` with:
```php
    public function __construct(private readonly LaravelOpenIDConnectClient $oidc) {}

    /**
     * @throws OpenIDConnectClientException
     */
    public function redirectToLogin(): RedirectResponse
    {
        Log::info('[SPID] Redirecting to AAC Trentino login');

        return $this->oidc->authorizationRedirect();
    }
```
Add the imports `Illuminate\Http\RedirectResponse` and `OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient`, and remove `use Jumbojett\OpenIDConnectClient;`. Leave the other methods for Task 7.

- [ ] **Step 6: Routes**

`routes/spid-trentino-auth.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;

$controller = Config::get('spid-laravel-trentino.auth_controller', SpidAuthController::class);

Route::middleware('web')->group(function () use ($controller): void {
    Route::get(Config::string('spid-laravel-trentino.routes.login'), [$controller, 'login'])->name('spid.login');
    Route::get(Config::string('spid-laravel-trentino.routes.callback'), [$controller, 'callback'])->name('spid.callback');
    Route::post(Config::string('spid-laravel-trentino.routes.logout'), [$controller, 'logout'])->name('spid.logout');
});
```

- [ ] **Step 7: Controller `login()` and the trait's failure helper**

In `src/Http/Controllers/SpidAuthController.php` add:
```php
    public function login(SpidTrentino $spid): RedirectResponse
    {
        try {
            return $spid->redirectToLogin();
        } catch (OpenIDConnectClientException $exception) {
            return $this->spidLoginFailed($exception);
        }
    }
```
In `src/Traits/SpidAuthenticatesUsers.php` add (imports: `Illuminate\Http\RedirectResponse`, `Illuminate\Support\Facades\Redirect`, `Jumbojett\OpenIDConnectClientException`, `OfflineAgency\SpidLaravelTrentino\SessionKeys`):
```php
    /**
     * Logs the failure and sends the user to error_redirect_to with a flash message.
     */
    protected function spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse
    {
        Log::error('[SPID] Authentication failed', ['exception' => $exception]);

        return Redirect::to(Config::string('spid-laravel-trentino.error_redirect_to'))
            ->with(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
    }
```

- [ ] **Step 8: `.env.example`**

```
SPID_TRENTINO_CLIENT_ID=your-client-id
SPID_TRENTINO_CLIENT_SECRET=your-client-secret
SPID_TRENTINO_REDIRECT_URI=https://your-app.test/spid/callback
SPID_TRENTINO_PROVIDER_URL=https://aac-test.cloud-test.tndigit.it
SPID_TRENTINO_SCOPES="openid profile.codicefiscale.me email offline_access"
SPID_TRENTINO_REDIRECT_TO=
```

- [ ] **Step 9: Run the tests**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php tests/Feature/AuthControllerTest.php`, then `composer test`
Expected: PASS. If `Config::boolean` is missing on the lowest Laravel 12 release, replace it with `(bool) Config::get(...)` and note it.

- [ ] **Step 10: Commit** (three commits; use `git add -p` on the provider to split the alias lines from the binding lines)

```bash
git add src/SpidTrentinoFacade.php src/SpidTrentino.php tests/Feature/ServiceProviderTest.php
git add -p src/SpidTrentinoServiceProvider.php   # stage everything except the two aliasMiddleware lines
git commit -m "fix: bind SpidTrentino per request and point the facade at it" -m "The facade resolved 'spid-trentino' while the container bound 'spid-laravel-trentino.auth'. SpidTrentino now receives the OIDC client from the container, so tests and apps can swap it, and the constructor no longer logs credentials."
git add src/SpidTrentinoServiceProvider.php
git commit -m "feat: register spid.valid and spid.refresh middleware aliases automatically"
```
Then:
```bash
git add routes .env.example src/Http/Controllers/SpidAuthController.php src/Traits/SpidAuthenticatesUsers.php tests/Feature/AuthControllerTest.php
git commit -m "fix: move the default logout route to /spid/logout and make routes optional" -m "POST /logout collided with Breeze, Jetstream and Fortify. Routes can be disabled with register_routes. The login route now returns a redirect response, and AAC failures redirect to error_redirect_to with a flash message."
```

---

### Task 7: `SpidTrentino` service: callback, tokens, refresh, logout, privacy

**Files:**
- Create: `src/Support/TokenResponse.php`, `src/Support/LogRedactor.php`, `tests/Unit/TokenResponseTest.php`, `tests/Unit/LogRedactorTest.php`, `tests/Feature/SpidTrentinoServiceTest.php`
- Modify: `src/SpidTrentino.php` (final version below), `tests/Pest.php` (helper `useCallbackRequest()`)

**Interfaces:**
- Produces: `TokenResponse::from(mixed): TokenResponse` with readonly `?string $accessToken, $refreshToken, $idToken, $error`, `?int $expiresIn`, method `expiresAt(): ?CarbonImmutable`.
- Produces: `LogRedactor::hash(string): string` (16 hex chars, '' for ''), `LogRedactor::user(SpidTrentinoUser): array{sub: string, fiscal_code: string}`.
- Produces: `SpidTrentino::handleCallback(): SpidTrentinoUser`, `refreshAccessToken(): void`, `logout(): void`, `getUserInfo(): ?object`.

- [ ] **Step 1: Add the request helper to `tests/Pest.php`**

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Makes the given query the current request, as the callback route would.
 *
 * @param  array<string, mixed>  $query
 */
function useCallbackRequest(array $query): void
{
    app()->instance('request', Request::create('/spid/callback', 'GET', $query));
    Facade::clearResolvedInstance('request');
}
```

- [ ] **Step 2: Write the failing unit tests**

`tests/Unit/TokenResponseTest.php`:
```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use OfflineAgency\SpidLaravelTrentino\Support\TokenResponse;

it('normalizes object and array token responses alike', function (mixed $raw) {
    $tokens = TokenResponse::from($raw);

    expect($tokens->accessToken)->toBe('access')
        ->and($tokens->refreshToken)->toBe('refresh')
        ->and($tokens->idToken)->toBe('id')
        ->and($tokens->expiresIn)->toBe(3600)
        ->and($tokens->error)->toBeNull();
})->with([
    'stdClass (what jumbojett returns)' => [(object) ['access_token' => 'access', 'refresh_token' => 'refresh', 'id_token' => 'id', 'expires_in' => 3600]],
    'array' => [['access_token' => 'access', 'refresh_token' => 'refresh', 'id_token' => 'id', 'expires_in' => '3600']],
]);

it('treats missing, empty and malformed fields as absent', function (mixed $raw) {
    $tokens = TokenResponse::from($raw);

    expect($tokens->accessToken)->toBeNull()
        ->and($tokens->refreshToken)->toBeNull()
        ->and($tokens->expiresIn)->toBeNull()
        ->and($tokens->expiresAt())->toBeNull();
})->with([
    'null' => [null],
    'string' => ['garbage'],
    'empty values' => [['access_token' => '', 'refresh_token' => 42, 'expires_in' => 'soon']],
]);

it('exposes the provider error', function () {
    $tokens = TokenResponse::from((object) ['error' => 'invalid_grant', 'error_description' => 'expired']);

    expect($tokens->error)->toBe('invalid_grant')->and($tokens->accessToken)->toBeNull();
});

it('computes the expiry from expires_in', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));

    expect(TokenResponse::from(['expires_in' => 60])->expiresAt()?->toIso8601String())
        ->toBe('2026-01-01T12:01:00+00:00');
});
```

`tests/Unit/LogRedactorTest.php`:
```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;

it('hashes values with the application key', function () {
    $hash = LogRedactor::hash('TINIT-RSSMRA80A01H501U');

    expect($hash)->toMatch('/^[0-9a-f]{16}$/')
        ->and($hash)->toBe(LogRedactor::hash('TINIT-RSSMRA80A01H501U'))
        ->and($hash)->not->toContain('RSSMRA');

    config()->set('app.key', 'another-key');
    expect(LogRedactor::hash('TINIT-RSSMRA80A01H501U'))->not->toBe($hash);
});

it('keeps empty values empty and tolerates a missing app key', function () {
    expect(LogRedactor::hash(''))->toBe('');

    config()->set('app.key', null);
    expect(LogRedactor::hash('x'))->toMatch('/^[0-9a-f]{16}$/');
});

it('redacts a SPID user for log context', function () {
    $user = SpidTrentinoUser::fromArray(['sub' => 'subject-123', 'enti-codicefiscale' => ['fiscalCode' => 'TINIT-RSSMRA80A01H501U']]);

    expect(LogRedactor::user($user))->toBe([
        'sub' => LogRedactor::hash('subject-123'),
        'fiscal_code' => LogRedactor::hash('TINIT-RSSMRA80A01H501U'),
    ]);
});
```

Run: `vendor/bin/pest tests/Unit/TokenResponseTest.php tests/Unit/LogRedactorTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement the two support classes**

`src/Support/TokenResponse.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Token endpoint response normalized from what jumbojett returns (stdClass),
 * or from an array.
 */
final readonly class TokenResponse
{
    public function __construct(
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?string $idToken,
        public ?int $expiresIn,
        public ?string $error,
    ) {}

    public static function from(mixed $response): self
    {
        $data = match (true) {
            $response instanceof stdClass => get_object_vars($response),
            is_array($response) => $response,
            default => [],
        };

        return new self(
            self::string($data, 'access_token'),
            self::string($data, 'refresh_token'),
            self::string($data, 'id_token'),
            is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : null,
            self::string($data, 'error'),
        );
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresIn === null ? null : CarbonImmutable::now()->addSeconds($this->expiresIn);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

`src/Support/LogRedactor.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Illuminate\Support\Facades\Config;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

/**
 * Pseudonymizes identifiers for logs: a keyed hash lets support correlate log
 * lines for the same person without storing the fiscal code. Fiscal codes have
 * low entropy, so a plain unsalted hash would be reversible.
 */
final class LogRedactor
{
    public static function hash(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = Config::get('app.key');

        return substr(hash_hmac('sha256', $value, is_string($key) ? $key : ''), 0, 16);
    }

    /**
     * @return array{sub: string, fiscal_code: string}
     */
    public static function user(SpidTrentinoUser $user): array
    {
        return [
            'sub' => self::hash($user->getSub()),
            'fiscal_code' => self::hash($user->getFiscalNumber()),
        ];
    }
}
```

Run Step 2's command. Expected: PASS.

- [ ] **Step 4: Write the failing service tests**

`tests/Feature/SpidTrentinoServiceTest.php`:
```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(SpidTrentino::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));
    FakeAacProvider::startAuthorization();
    useCallbackRequest(['code' => 'auth-code', 'state' => FakeAacProvider::STATE]);
});

it('stores tokens, expiry and user, dispatches the login event and returns the user', function () {
    Event::fake([SpidTrentinoLoggedIn::class]);
    FakeAacProvider::fake();

    $user = app(SpidTrentino::class)->handleCallback();

    expect($user->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and($user->getEmail())->toBe('mario.rossi@example.com')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN))->toBe(FakeAacProvider::ACCESS_TOKEN)
        ->and(Session::get(SessionKeys::REFRESH_TOKEN))->toBe(FakeAacProvider::REFRESH_TOKEN)
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00')
        ->and(Session::get(SessionKeys::USER))->toBe($user->toArray());

    Event::assertDispatched(SpidTrentinoLoggedIn::class, fn (SpidTrentinoLoggedIn $event) => $event->getUser()->getFiscalNumber() === FakeAacProvider::FISCAL_CODE);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), FakeAacProvider::ISSUER.'/userinfo')
        && $request->hasHeader('Authorization', 'Bearer '.FakeAacProvider::ACCESS_TOKEN));
});

it('handles array token responses from custom clients', function () {
    app()->bind(LaravelOpenIDConnectClient::class, fn () => new class(FakeAacProvider::ISSUER, FakeAacProvider::CLIENT_ID) extends LaravelOpenIDConnectClient
    {
        public function authenticate(): bool
        {
            return true;
        }

        public function getTokenResponse(): array
        {
            return ['access_token' => 'array-access', 'expires_in' => '120'];
        }

        public function requestUserInfo(?string $attribute = null): mixed
        {
            return (object) FakeAacProvider::userInfo();
        }
    });
    app()->forgetScopedInstances();

    app(SpidTrentino::class)->handleCallback();

    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('array-access')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T12:02:00+00:00')
        ->and(Session::has(SessionKeys::REFRESH_TOKEN))->toBeFalse();
});

it('stores no expiry when AAC sends no expires_in', function () {
    FakeAacProvider::fake(['expires_in' => null]);

    app(SpidTrentino::class)->handleCallback();

    expect(Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();
});

it('refuses a userinfo without fiscal code', function (array $userInfo) {
    FakeAacProvider::fake(userInfo: $userInfo);

    expect(fn () => app(SpidTrentino::class)->handleCallback())
        ->toThrow(OpenIDConnectClientException::class, 'AAC did not return a fiscal code for the authenticated user.');
    expect(Session::has(SessionKeys::USER))->toBeFalse();
})->with([
    'claim missing' => [['enti-codicefiscale' => null]],
    'empty fiscal code' => [['enti-codicefiscale' => ['fiscalCode' => '']]],
]);

it('refuses a userinfo that is not a JSON object', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/jwk' => Http::response(FakeAacProvider::jwks()),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(FakeAacProvider::tokenResponse()),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response('[]'),
    ]);

    expect(fn () => app(SpidTrentino::class)->handleCallback())->toThrow(OpenIDConnectClientException::class);
});

it('never logs tokens, credentials or personal data', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    FakeAacProvider::fake();
    Session::put(SessionKeys::REFRESH_TOKEN, FakeAacProvider::REFRESH_TOKEN);

    $spid = app(SpidTrentino::class);
    $spid->handleCallback();
    $spid->refreshAccessToken();
    $spid->logout();

    $all = implode("\n", $logged);
    expect($logged)->not->toBeEmpty();
    foreach ([FakeAacProvider::ACCESS_TOKEN, FakeAacProvider::REFRESH_TOKEN, FakeAacProvider::CLIENT_SECRET, FakeAacProvider::FISCAL_CODE, 'RSSMRA80A01H501U', 'Mario', 'Rossi', 'mario.rossi@example.com', 'eyJ'] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('refreshes the access token with the stored refresh token', function () {
    FakeAacProvider::fake(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 600, 'id_token' => null]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');

    app(SpidTrentino::class)->refreshAccessToken();

    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('new-access')
        ->and(Session::get(SessionKeys::REFRESH_TOKEN))->toBe('new-refresh')
        ->and(Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T12:10:00+00:00');
    Http::assertSent(fn ($request) => $request->url() === FakeAacProvider::ISSUER.'/oauth/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

it('keeps the current refresh token when AAC does not rotate it', function () {
    FakeAacProvider::fake(['access_token' => 'new-access', 'refresh_token' => null, 'id_token' => null]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');

    app(SpidTrentino::class)->refreshAccessToken();

    expect(Session::get(SessionKeys::REFRESH_TOKEN))->toBe('old-refresh');
});

it('throws when AAC refuses the refresh', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);
    Session::put(SessionKeys::REFRESH_TOKEN, 'old-refresh');
    Session::put(SessionKeys::ACCESS_TOKEN, 'old-access');

    expect(fn () => app(SpidTrentino::class)->refreshAccessToken())
        ->toThrow(OpenIDConnectClientException::class, 'AAC refused the token refresh: invalid_grant');
    expect(Session::get(SessionKeys::ACCESS_TOKEN))->toBe('old-access');
});

it('does nothing without a refresh token', function (mixed $stored) {
    Http::fake();
    Session::put(SessionKeys::REFRESH_TOKEN, $stored);

    app(SpidTrentino::class)->refreshAccessToken();

    Http::assertNothingSent();
})->with(['missing' => null, 'empty' => '']);

it('logs out, ends the session and dispatches the logout event', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'm@example.com', 'password' => 'x']);
    $this->actingAs($user);
    Session::put(SessionKeys::USER, FakeAacProvider::userInfo());
    Session::put(SessionKeys::ACCESS_TOKEN, 'access');

    app(SpidTrentino::class)->logout();

    $this->assertGuest();
    expect(Session::has(SessionKeys::USER))->toBeFalse()
        ->and(Session::has(SessionKeys::ACCESS_TOKEN))->toBeFalse();
    Event::assertDispatched(SpidTrentinoLoggedOut::class, fn (SpidTrentinoLoggedOut $event) => $event->getUser()->getFiscalNumber() === FakeAacProvider::FISCAL_CODE);
});

it('does not dispatch the logout event without a SPID user', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    Session::put(SessionKeys::USER, 'corrupted');

    app(SpidTrentino::class)->logout();

    Event::assertNotDispatched(SpidTrentinoLoggedOut::class);
});

it('requests userinfo with the access token kept in the session', function () {
    FakeAacProvider::fake();
    Session::put(SessionKeys::ACCESS_TOKEN, 'session-access');

    $userInfo = app(SpidTrentino::class)->getUserInfo();

    expect($userInfo)->toBeObject()->and($userInfo->sub)->toBe('subject-123');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer session-access'));
});

it('returns null when userinfo is not an object', function () {
    Http::fake([
        FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
        FakeAacProvider::ISSUER.'/userinfo*' => Http::response('[]'),
    ]);

    expect(app(SpidTrentino::class)->getUserInfo())->toBeNull();
});

it('returns a SpidTrentinoUser from the facade callback', function () {
    FakeAacProvider::fake();

    expect(\SpidTrentino::handleCallback())->toBeInstanceOf(SpidTrentinoUser::class);
});
```
`SpidTrentinoUser::getEmail()` is added in Task 10. Until then, delete the `getEmail` expectation line and restore it in Task 10, Step 1 (that restoration is Task 10's red step).

- [ ] **Step 5: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/SpidTrentinoServiceTest.php`
Expected: FAIL. The expiry is not stored (the `is_array` check against the `stdClass`), the keys are wrong, `handleCallback()` returns void, logout raises `Cannot access private property ... $fiscalNumber` (or an undefined property error), tokens appear in the log, and refresh throws a `TypeError` on the 400 response.

- [ ] **Step 6: Replace `src/SpidTrentino.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use OfflineAgency\SpidLaravelTrentino\Support\TokenResponse;
use stdClass;

class SpidTrentino
{
    public function __construct(private readonly LaravelOpenIDConnectClient $oidc) {}

    /**
     * @throws OpenIDConnectClientException
     */
    public function redirectToLogin(): RedirectResponse
    {
        Log::info('[SPID] Redirecting to AAC Trentino login');

        return $this->oidc->authorizationRedirect();
    }

    /**
     * Completes the authorization code flow and stores tokens and user in the
     * session. The ID token signature and claims are verified by the OIDC
     * client inside authenticate().
     *
     * @throws OpenIDConnectClientException
     */
    public function handleCallback(): SpidTrentinoUser
    {
        Log::info('[SPID] Handling AAC callback');

        $this->oidc->authenticate();
        $tokens = TokenResponse::from($this->oidc->getTokenResponse());
        $userInfo = $this->oidc->requestUserInfo();
        $user = new SpidTrentinoUser($userInfo instanceof stdClass ? $userInfo : []);

        if ($user->getFiscalNumber() === '') {
            throw new OpenIDConnectClientException('AAC did not return a fiscal code for the authenticated user.');
        }

        $this->storeTokens($tokens);
        Session::put(SessionKeys::USER, $user->toArray());

        Log::info('[SPID] User authenticated by AAC', LogRedactor::user($user));
        Event::dispatch(new SpidTrentinoLoggedIn($user));

        return $user;
    }

    /**
     * @throws OpenIDConnectClientException when AAC refuses the refresh or cannot be reached
     */
    public function refreshAccessToken(): void
    {
        $refreshToken = Session::get(SessionKeys::REFRESH_TOKEN);

        if (! is_string($refreshToken) || $refreshToken === '') {
            Log::warning('[SPID] No refresh token in session');

            return;
        }

        $tokens = TokenResponse::from($this->oidc->refreshToken($refreshToken));

        if ($tokens->accessToken === null) {
            throw new OpenIDConnectClientException('AAC refused the token refresh: '.($tokens->error ?? 'no access token returned'));
        }

        $this->storeTokens($tokens, $refreshToken);
        Log::info('[SPID] Access token refreshed');
    }

    /**
     * Logs the user out of the application, ends the session and dispatches
     * SpidTrentinoLoggedOut. The SPID user is read before the session ends.
     */
    public function logout(): void
    {
        $payload = Session::get(SessionKeys::USER);
        $user = new SpidTrentinoUser(is_array($payload) ? $payload : []);

        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        if ($user->getFiscalNumber() !== '') {
            Log::info('[SPID] User logged out', LogRedactor::user($user));
            Event::dispatch(new SpidTrentinoLoggedOut($user));
        }
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function getUserInfo(): ?object
    {
        $accessToken = Session::get(SessionKeys::ACCESS_TOKEN);

        if (is_string($accessToken)) {
            $this->oidc->setAccessToken($accessToken);
        }

        $userInfo = $this->oidc->requestUserInfo();

        return is_object($userInfo) ? $userInfo : null;
    }

    private function storeTokens(TokenResponse $tokens, ?string $currentRefreshToken = null): void
    {
        Session::put(SessionKeys::ACCESS_TOKEN, $tokens->accessToken);

        $refreshToken = $tokens->refreshToken ?? $currentRefreshToken;

        if ($refreshToken === null) {
            Session::forget(SessionKeys::REFRESH_TOKEN);
        } else {
            Session::put(SessionKeys::REFRESH_TOKEN, $refreshToken);
        }

        SessionExpiry::put($tokens->expiresAt());
    }
}
```
The events still use `SerializesModels` and a `setUser()` signature until Task 10. If the event constructor rejects the typed DTO, the test output will say so. Task 10 rewrites the events.

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/pest tests/Feature/SpidTrentinoServiceTest.php tests/Unit`
Expected: PASS. Investigate any unexplained failure with superpowers:systematic-debugging.

- [ ] **Step 8: Commit** (one commit per bug, staging with `git add -p` as needed)

```bash
git add src/Support/TokenResponse.php tests/Unit/TokenResponseTest.php
git commit -m "feat: normalize OIDC token responses in TokenResponse"
git add src/Support/LogRedactor.php tests/Unit/LogRedactorTest.php
git commit -m "feat: add LogRedactor for pseudonymized log context"
git add src/SpidTrentino.php tests/Feature/SpidTrentinoServiceTest.php tests/Pest.php
git commit -m "fix: store token expiry and session data once from the stdClass token response" -m "jumbojett returns a stdClass, so the is_array() checks never stored the expiry or ran the ID token validation. ID token verification is left to the OIDC client (see LaravelOpenIDConnectClient). Tokens are written once, the callback fails when AAC returns no fiscal code, refresh failures throw instead of keeping stale tokens, logout uses getFiscalNumber() and dispatches SpidTrentinoLoggedOut, and logs never contain tokens or personal data."
```

---

### Task 8: Controller callback and logout through one path

**Files:**
- Modify: `src/Http/Controllers/SpidAuthController.php` (final below), `src/Traits/SpidAuthenticatesUsers.php` (remove `spidLogout()`), `tests/Feature/AuthControllerTest.php` (append)

**Interfaces:**
- Consumes: `SpidTrentino::handleCallback(): SpidTrentinoUser`, `SpidTrentino::logout()`, trait `authenticateFromSpid()`, `spidLoginFailed()`, `redirectTo()`.

- [ ] **Step 1: Append failing tests to `tests/Feature/AuthControllerTest.php`**

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;

it('logs the user in on callback and redirects to the intended page', function () {
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE)->assertRedirect('/');

    $this->assertAuthenticated();
});

it('accepts the session created by the callback on spid.valid routes', function () {
    Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->get('/reserved', fn () => 'reserved');
    FakeAacProvider::fake();
    FakeAacProvider::startAuthorization();

    $this->get('/spid/callback?code=auth-code&state='.FakeAacProvider::STATE);

    $this->get('/reserved')->assertOk()->assertSee('reserved');
});

it('redirects to the error page with a flash message when the callback fails', function (array $query, array $idTokenClaims) {
    FakeAacProvider::fake($idTokenClaims === [] ? [] : ['id_token' => FakeAacProvider::idToken($idTokenClaims)]);
    FakeAacProvider::startAuthorization();
    config()->set('spid-laravel-trentino.error_redirect_to', '/login-failed');

    $this->get('/spid/callback?'.http_build_query($query))
        ->assertRedirect('/login-failed')
        ->assertSessionHas(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'user cancelled on AAC' => [['error' => 'access_denied'], []],
    'forged state' => [['code' => 'auth-code', 'state' => 'forged'], []],
    'ID token for another client' => [['code' => 'auth-code', 'state' => FakeAacProvider::STATE], ['aud' => 'another-client']],
]);

it('logs out through the service, dispatching the logout event', function () {
    Event::fake([SpidTrentinoLoggedOut::class]);
    $user = User::query()->forceCreate(['name' => 'Mario', 'email' => 'm@example.com', 'password' => 'x']);
    config()->set('spid-laravel-trentino.logout_redirect_to', '/goodbye');

    $this->actingAs($user)
        ->withSession([SessionKeys::USER => FakeAacProvider::userInfo()])
        ->post('/spid/logout')
        ->assertRedirect('/goodbye');

    $this->assertGuest();
    Event::assertDispatched(SpidTrentinoLoggedOut::class);
});
```
The success tests need the users table to have the SPID columns, which arrive in Task 11. Until then mark the two success tests `->todo()`. Task 11 removes the `->todo()`, and that is its red step.

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/AuthControllerTest.php`
Expected: FAIL. The callback failures return 500 (the exception is rethrown), and logout dispatches no event (the trait's `spidLogout()` path).

- [ ] **Step 3: Final controller**

`src/Http/Controllers/SpidAuthController.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redirect;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

class SpidAuthController extends Controller
{
    use SpidAuthenticatesUsers;

    public function login(SpidTrentino $spid): RedirectResponse
    {
        try {
            return $spid->redirectToLogin();
        } catch (OpenIDConnectClientException $exception) {
            return $this->spidLoginFailed($exception);
        }
    }

    public function callback(SpidTrentino $spid): RedirectResponse
    {
        try {
            $spidUser = $spid->handleCallback();
        } catch (OpenIDConnectClientException $exception) {
            return $this->spidLoginFailed($exception);
        }

        $this->authenticateFromSpid($spidUser);

        return Redirect::intended($this->redirectTo());
    }

    public function logout(SpidTrentino $spid): RedirectResponse
    {
        $spid->logout();

        return Redirect::to(Config::string('spid-laravel-trentino.logout_redirect_to'));
    }
}
```
Delete `spidLogout()` from the trait (replaced by `SpidTrentino::logout()`; this goes in UPGRADE.md).

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/AuthControllerTest.php` then `composer test`
Expected: PASS (todos reported as todo).

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/SpidAuthController.php tests/Feature/AuthControllerTest.php
git commit -m "fix: redirect to error_redirect_to instead of rethrowing callback failures"
git add src/Traits/SpidAuthenticatesUsers.php
git commit -m "fix: route controller logout through SpidTrentino::logout()" -m "SpidAuthenticatesUsers::spidLogout() duplicated the logout without dispatching SpidTrentinoLoggedOut; it is removed."
```

---

### Task 9: `RefreshSpidTokenIfNeeded`

**Files:**
- Modify: `src/Http/Middleware/RefreshSpidTokenIfNeeded.php` (final below)
- Create: `tests/Feature/RefreshSpidTokenIfNeededTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T12:00:00+00:00'));
    Route::middleware(['web', 'spid.refresh'])->get('/refreshing', fn () => 'ok');
    Route::middleware(['web', 'spid.refresh', 'spid.valid'])->get('/guarded', fn () => 'ok');
});

/** @return array<string, mixed> */
function tokenSession(mixed $expiresAt): array
{
    return [
        SessionKeys::USER => FakeAacProvider::userInfo(),
        SessionKeys::ACCESS_TOKEN => 'old-access',
        SessionKeys::REFRESH_TOKEN => 'old-refresh',
        SessionKeys::ACCESS_TOKEN_EXPIRES_AT => $expiresAt,
    ];
}

it('does nothing without a refresh token or far from expiry', function (array $session) {
    Http::fake();

    $this->withSession($session)->get('/refreshing')->assertOk();

    Http::assertNothingSent();
})->with([
    'no refresh token' => [[SessionKeys::ACCESS_TOKEN_EXPIRES_AT => '2026-01-01T12:00:30+00:00']],
    'far from expiry' => [tokenSession('2026-01-01T13:00:00+00:00')],
    'no expiry known' => [array_diff_key(tokenSession(null), [SessionKeys::ACCESS_TOKEN_EXPIRES_AT => true])],
]);

it('refreshes the token within a minute of expiry', function (mixed $expiresAt) {
    FakeAacProvider::fake(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => null]);

    $this->withSession(tokenSession($expiresAt))->get('/refreshing')->assertOk();

    expect(session(SessionKeys::ACCESS_TOKEN))->toBe('new-access')
        ->and(session(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBe('2026-01-01T13:00:00+00:00');
})->with([
    'ISO string' => '2026-01-01T12:00:30+00:00',
    'legacy Carbon' => fn () => CarbonImmutable::parse('2026-01-01T12:00:30+00:00'),
    'already expired' => '2026-01-01T11:00:00+00:00',
]);

it('forgets the tokens when the refresh fails so spid.valid ends the session', function (string $failure) {
    match ($failure) {
        'invalid_grant' => Http::fake([
            FakeAacProvider::discoveryUrl() => Http::response(FakeAacProvider::discovery()),
            FakeAacProvider::ISSUER.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]),
        'unreachable' => Http::fake(fn () => throw new ConnectionException('timeout')),
    };

    $this->withSession(tokenSession('2026-01-01T12:00:30+00:00'))->get('/refreshing')->assertOk();

    expect(session()->has(SessionKeys::ACCESS_TOKEN))->toBeFalse()
        ->and(session()->has(SessionKeys::REFRESH_TOKEN))->toBeFalse()
        ->and(session()->has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT))->toBeFalse();

    $this->get('/guarded')->assertRedirect('/spid/login');
})->with(['invalid_grant', 'unreachable']);
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/RefreshSpidTokenIfNeededTest.php`
Expected: FAIL. The middleware reads the old `refresh_token` and `access_token_expires_at` keys, so no refresh happens, and a failure leaves the user logged in.

- [ ] **Step 3: Final middleware**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Support\SessionExpiry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refreshes the access token when it expires within a minute. When the
 * refresh fails the tokens are forgotten, so spid.valid (placed after this
 * middleware) ends the session.
 */
class RefreshSpidTokenIfNeeded
{
    private const int REFRESH_WINDOW_SECONDS = 60;

    public function __construct(private readonly SpidTrentino $spid) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Session::has(SessionKeys::REFRESH_TOKEN) && SessionExpiry::expiresWithin(self::REFRESH_WINDOW_SECONDS)) {
            try {
                $this->spid->refreshAccessToken();
            } catch (OpenIDConnectClientException $exception) {
                Log::warning('[SPID] Access token refresh failed', ['error' => $exception->getMessage()]);

                Session::forget([
                    SessionKeys::ACCESS_TOKEN,
                    SessionKeys::REFRESH_TOKEN,
                    SessionKeys::ACCESS_TOKEN_EXPIRES_AT,
                ]);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/RefreshSpidTokenIfNeededTest.php` then `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/RefreshSpidTokenIfNeeded.php tests/Feature/RefreshSpidTokenIfNeededTest.php
git commit -m "fix: refresh tokens from the package session keys and end the session on failure"
```

---

### Task 10: DTO (`email`, tolerant hydration), events and `MockOpenIDConnectClient`

**Files:**
- Modify: `src/SpidTrentinoUser.php` (final below), `src/Events/SpidTrentinoLoggedIn.php`, `src/Events/SpidTrentinoLoggedOut.php`, `src/Testing/MockOpenIDConnectClient.php`, `tests/Feature/SpidTrentinoServiceTest.php` (restore `getEmail` line)
- Create: `tests/Unit/SpidTrentinoUserTest.php`, `tests/Unit/EventsTest.php`, `tests/Feature/MockOpenIDConnectClientTest.php`

**Interfaces:**
- Produces: `SpidTrentinoUser::getEmail(): string`, `setEmail(string): self`; `toArray()` gains `'email'`. Events: `public readonly SpidTrentinoUser $user` plus `getUser()`. Mock: `new MockOpenIDConnectClient()`, `withUserInfo(array): static`, constants `ACCESS_TOKEN`, `REFRESH_TOKEN`, `ID_TOKEN`, `FISCAL_CODE`.

- [ ] **Step 1: Restore the `getEmail()` expectation in `SpidTrentinoServiceTest` and write the DTO tests**

`tests/Unit/SpidTrentinoUserTest.php`:
```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;
use OfflineAgency\SpidLaravelTrentino\Tests\Support\FakeAacProvider;

mutates(SpidTrentinoUser::class);

it('maps every AAC claim', function () {
    $user = SpidTrentinoUser::fromArray(FakeAacProvider::userInfo());

    expect($user->getSub())->toBe('subject-123')
        ->and($user->getName())->toBe('Mario')
        ->and($user->getGivenName())->toBe('Mario')
        ->and($user->getSurname())->toBe('Rossi')
        ->and($user->getFamilyName())->toBe('Rossi')
        ->and($user->getEmail())->toBe('mario.rossi@example.com')
        ->and($user->getPreferredUsername())->toBe('mario.rossi')
        ->and($user->getLocale())->toBe('it')
        ->and($user->getZoneinfo())->toBe('Europe/Rome')
        ->and($user->getRealm())->toBe('test-realm')
        ->and($user->getId())->toBe('user-123')
        ->and($user->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and($user->getEntiCodiceFiscale())->toBe(['fiscalCode' => FakeAacProvider::FISCAL_CODE, 'id' => 'cf-1'])
        ->and($user->getEntiSpid())->toBe(['isSpid' => 'true', 'spidCode' => 'TEST0000000001', 'id' => 'spid-1'])
        ->and($user->getEntiAcr())->toBe(['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'acr-1'])
        ->and($user->getEntiIssuerSource())->toBe(['issuerSource' => 'https://idp.test', 'id' => 'issuer-1']);
});

it('round-trips through toArray and JSON', function () {
    $user = SpidTrentinoUser::fromArray(FakeAacProvider::userInfo());

    expect(SpidTrentinoUser::fromArray($user->toArray())->toArray())->toBe($user->toArray())
        ->and(json_encode($user))->toBe(json_encode($user->toArray()))
        ->and($user->toArray())->toHaveKeys(['sub', 'email', 'enti-codicefiscale', 'family_name']);
});

it('hydrates from nested stdClass objects as returned by jumbojett', function () {
    $payload = json_decode((string) json_encode(FakeAacProvider::userInfo()));

    expect(SpidTrentinoUser::fromStdClass($payload)->getFiscalNumber())->toBe(FakeAacProvider::FISCAL_CODE)
        ->and((new SpidTrentinoUser($payload))->getEmail())->toBe('mario.rossi@example.com');
});

it('hydrates from JSON', function () {
    expect(SpidTrentinoUser::fromJson((string) json_encode(FakeAacProvider::userInfo()))->getSub())->toBe('subject-123');
});

it('rejects invalid JSON unless asked not to throw', function (string $json) {
    expect(fn () => SpidTrentinoUser::fromJson($json))->toThrow(InvalidArgumentException::class)
        ->and(SpidTrentinoUser::fromJson($json, false)->toArray())->toBe((new SpidTrentinoUser)->toArray());
})->with(['malformed' => '{"sub":', 'not an object' => '"just a string"']);

it('ignores claims of unexpected types instead of failing', function () {
    $user = SpidTrentinoUser::fromArray([
        'sub' => 12345,
        'email' => ['not', 'a', 'string'],
        'locale' => null,
        'enti-codicefiscale' => 'TINIT-RSSMRA80A01H501U',
    ]);

    expect($user->getSub())->toBe('12345')
        ->and($user->getEmail())->toBe('')
        ->and($user->getLocale())->toBe('')
        ->and($user->getEntiCodiceFiscale())->toBe([])
        ->and($user->getFiscalNumber())->toBe('');
});

it('exposes fluent setters', function () {
    $user = (new SpidTrentinoUser)
        ->setSub('s')->setZoneinfo('z')->setPreferredUsername('p')->setLocale('l')
        ->setGivenName('g')->setRealm('r')->setId('i')->setFamilyName('f')->setEmail('e@example.com')
        ->setEntiIssuerSource((object) ['issuerSource' => 'x'])->setEntiAcr(['acr' => 'y'])
        ->setEntiSpid(['isSpid' => 'true'])->setEntiCodiceFiscale(['fiscalCode' => 'TINIT-X']);

    expect($user->toArray())->toBe([
        'sub' => 's', 'zoneinfo' => 'z', 'enti-issuersource' => ['issuerSource' => 'x'],
        'preferred_username' => 'p', 'locale' => 'l', 'given_name' => 'g', 'email' => 'e@example.com',
        'enti-acr' => ['acr' => 'y'], 'enti-spid' => ['isSpid' => 'true'], 'realm' => 'r',
        'enti-codicefiscale' => ['fiscalCode' => 'TINIT-X'], 'id' => 'i', 'family_name' => 'f',
    ]);
});
```

`tests/Unit/EventsTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Queue\SerializesModels;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

it('carries the SPID user without model serialization', function (string $event) {
    $user = SpidTrentinoUser::fromArray(['sub' => 'subject-123']);
    $instance = new $event($user);

    expect($instance->user)->toBe($user)
        ->and($instance->getUser())->toBe($user)
        ->and(class_uses($instance))->not->toContain(SerializesModels::class)
        ->and(unserialize(serialize($instance))->getUser()->getSub())->toBe('subject-123');
})->with([SpidTrentinoLoggedIn::class, SpidTrentinoLoggedOut::class]);
```

`tests/Feature/MockOpenIDConnectClientTest.php`:
```php
<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient;

beforeEach(function () {
    app()->instance(LaravelOpenIDConnectClient::class, (new MockOpenIDConnectClient)->withUserInfo(['given_name' => 'Giulia']));
    app()->forgetScopedInstances();
});

it('lets apps fake the whole SPID login', function () {
    $this->get('/spid/login')->assertRedirect('https://aac.mock.invalid/authorize');

    $this->get('/spid/callback')->assertRedirect('/');

    $this->assertAuthenticated();
    expect(session(SessionKeys::USER)['enti-codicefiscale']['fiscalCode'])->toBe(MockOpenIDConnectClient::FISCAL_CODE)
        ->and(session(SessionKeys::USER)['given_name'])->toBe('Giulia')
        ->and(session(SessionKeys::ACCESS_TOKEN))->toBe(MockOpenIDConnectClient::ACCESS_TOKEN)
        ->and(session(SessionKeys::REFRESH_TOKEN))->toBe(MockOpenIDConnectClient::REFRESH_TOKEN);
});

it('fakes refresh and userinfo', function () {
    $mock = new MockOpenIDConnectClient;

    expect($mock->refreshToken('r')->access_token)->toBe('mock-refreshed-access-token')
        ->and($mock->getIdToken())->toBe(MockOpenIDConnectClient::ID_TOKEN)
        ->and($mock->requestUserInfo('family_name'))->toBe('Rossi')
        ->and($mock->requestUserInfo('missing'))->toBeNull();

    session()->put(SessionKeys::REFRESH_TOKEN, 'r');
    app(SpidTrentino::class)->refreshAccessToken();
    expect(session(SessionKeys::ACCESS_TOKEN))->toBe('mock-refreshed-access-token');
});
```
The first mock test needs the Task 11 migration, so mark it `->todo()` until Task 11 (whose red step removes it).

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Unit tests/Feature/MockOpenIDConnectClientTest.php tests/Feature/SpidTrentinoServiceTest.php`
Expected: FAIL. `getEmail()` is undefined, `sub` 12345 raises a `TypeError` under strict types, `fromJson('"just a string"')` raises a `TypeError`, the events use `SerializesModels`, and the mock has no `withUserInfo()` and still uses the `codicefiscale` key.

- [ ] **Step 3: Replace `src/SpidTrentinoUser.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * SPID identity returned by AAC Trentino's userinfo endpoint.
 *
 * Hydration is tolerant: claims of an unexpected type become '' (scalars) or
 * [] (nested enti-* claims) instead of failing.
 *
 * @implements Arrayable<string, mixed>
 */
class SpidTrentinoUser implements Arrayable, JsonSerializable
{
    private string $sub = '';

    private string $zoneinfo = '';

    private string $preferredUsername = '';

    private string $locale = '';

    private string $givenName = '';

    private string $realm = '';

    private string $id = '';

    private string $familyName = '';

    private string $email = '';

    /** @var array<array-key, mixed> */
    private array $entiIssuerSource = [];

    /** @var array<array-key, mixed> */
    private array $entiAcr = [];

    /** @var array<array-key, mixed> */
    private array $entiSpid = [];

    /** @var array<array-key, mixed> */
    private array $entiCodiceFiscale = [];

    /**
     * @param  array<array-key, mixed>|stdClass  $data  userinfo payload
     */
    public function __construct(array|stdClass $data = [])
    {
        $data = self::toArrayRecursive($data);

        $this->setSub(self::stringClaim($data, 'sub'))
            ->setZoneinfo(self::stringClaim($data, 'zoneinfo'))
            ->setPreferredUsername(self::stringClaim($data, 'preferred_username'))
            ->setLocale(self::stringClaim($data, 'locale'))
            ->setGivenName(self::stringClaim($data, 'given_name'))
            ->setRealm(self::stringClaim($data, 'realm'))
            ->setId(self::stringClaim($data, 'id'))
            ->setFamilyName(self::stringClaim($data, 'family_name'))
            ->setEmail(self::stringClaim($data, 'email'))
            ->setEntiIssuerSource(self::arrayClaim($data, 'enti-issuersource'))
            ->setEntiAcr(self::arrayClaim($data, 'enti-acr'))
            ->setEntiSpid(self::arrayClaim($data, 'enti-spid'))
            ->setEntiCodiceFiscale(self::arrayClaim($data, 'enti-codicefiscale'));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @throws InvalidArgumentException when $json is not a JSON object and $throwOnError is true
     */
    public static function fromJson(string $json, bool $throwOnError = true): self
    {
        $data = json_decode($json, true);

        if (is_array($data)) {
            return new self($data);
        }

        if ($throwOnError) {
            throw new InvalidArgumentException('Invalid SPID user JSON: '.(json_last_error() === JSON_ERROR_NONE ? 'not an object' : json_last_error_msg()));
        }

        return new self;
    }

    public static function fromStdClass(stdClass $object): self
    {
        return new self($object);
    }

    public function getSub(): string
    {
        return $this->sub;
    }

    public function setSub(string $sub): self
    {
        $this->sub = $sub;

        return $this;
    }

    public function getZoneinfo(): string
    {
        return $this->zoneinfo;
    }

    public function setZoneinfo(string $zoneinfo): self
    {
        $this->zoneinfo = $zoneinfo;

        return $this;
    }

    public function getPreferredUsername(): string
    {
        return $this->preferredUsername;
    }

    public function setPreferredUsername(string $preferredUsername): self
    {
        $this->preferredUsername = $preferredUsername;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getGivenName(): string
    {
        return $this->givenName;
    }

    public function setGivenName(string $givenName): self
    {
        $this->givenName = $givenName;

        return $this;
    }

    public function getRealm(): string
    {
        return $this->realm;
    }

    public function setRealm(string $realm): self
    {
        $this->realm = $realm;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFamilyName(): string
    {
        return $this->familyName;
    }

    public function setFamilyName(string $familyName): self
    {
        $this->familyName = $familyName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiIssuerSource(): array
    {
        return $this->entiIssuerSource;
    }

    /** @param array<array-key, mixed>|stdClass $entiIssuerSource */
    public function setEntiIssuerSource(array|stdClass $entiIssuerSource): self
    {
        $this->entiIssuerSource = self::toArrayRecursive($entiIssuerSource);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiAcr(): array
    {
        return $this->entiAcr;
    }

    /** @param array<array-key, mixed>|stdClass $entiAcr */
    public function setEntiAcr(array|stdClass $entiAcr): self
    {
        $this->entiAcr = self::toArrayRecursive($entiAcr);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiSpid(): array
    {
        return $this->entiSpid;
    }

    /** @param array<array-key, mixed>|stdClass $entiSpid */
    public function setEntiSpid(array|stdClass $entiSpid): self
    {
        $this->entiSpid = self::toArrayRecursive($entiSpid);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiCodiceFiscale(): array
    {
        return $this->entiCodiceFiscale;
    }

    /** @param array<array-key, mixed>|stdClass $entiCodiceFiscale */
    public function setEntiCodiceFiscale(array|stdClass $entiCodiceFiscale): self
    {
        $this->entiCodiceFiscale = self::toArrayRecursive($entiCodiceFiscale);

        return $this;
    }

    /**
     * Fiscal number from enti-codicefiscale.fiscalCode (for example TINIT-RSSMRA80A01H501U).
     */
    public function getFiscalNumber(): string
    {
        return self::stringClaim($this->entiCodiceFiscale, 'fiscalCode');
    }

    public function getName(): string
    {
        return $this->givenName;
    }

    public function getSurname(): string
    {
        return $this->familyName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'zoneinfo' => $this->zoneinfo,
            'enti-issuersource' => $this->entiIssuerSource,
            'preferred_username' => $this->preferredUsername,
            'locale' => $this->locale,
            'given_name' => $this->givenName,
            'email' => $this->email,
            'enti-acr' => $this->entiAcr,
            'enti-spid' => $this->entiSpid,
            'realm' => $this->realm,
            'enti-codicefiscale' => $this->entiCodiceFiscale,
            'id' => $this->id,
            'family_name' => $this->familyName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $value
     * @return array<array-key, mixed>
     */
    private static function toArrayRecursive(array|stdClass $value): array
    {
        $decoded = json_decode((string) json_encode($value), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function stringClaim(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arrayClaim(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
```
Note: `toArrayRecursive` always gets an array or a `stdClass`. `json_encode` of those never fails, so the `: []` arm is only defensive, and it sits on a covered line.

- [ ] **Step 4: Events** (same shape for both; `SpidTrentinoLoggedOut` shown)

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Events;

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

/**
 * Dispatched after SpidTrentino::logout() ended a SPID session.
 * The DTO is a plain serializable object, so SerializesModels is not needed.
 */
class SpidTrentinoLoggedOut
{
    public function __construct(public readonly SpidTrentinoUser $user) {}

    public function getUser(): SpidTrentinoUser
    {
        return $this->user;
    }
}
```
`SpidTrentinoLoggedIn` is identical, with the docblock "Dispatched after a successful AAC callback, before the local user is authenticated."

- [ ] **Step 5: Replace `src/Testing/MockOpenIDConnectClient.php`**

```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Testing;

use Illuminate\Http\RedirectResponse;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

/**
 * Drop-in OIDC client for application tests: no HTTP, fixed tokens and an
 * AAC-shaped userinfo payload.
 *
 *     $this->app->instance(LaravelOpenIDConnectClient::class, new MockOpenIDConnectClient);
 */
class MockOpenIDConnectClient extends LaravelOpenIDConnectClient
{
    public const string ACCESS_TOKEN = 'mock-access-token';

    public const string REFRESH_TOKEN = 'mock-refresh-token';

    public const string ID_TOKEN = 'mock-id-token';

    public const string FISCAL_CODE = 'TINIT-RSSMRA80A01H501U';

    /** @var array<string, mixed> */
    private array $userInfo = [
        'sub' => 'mock-subject',
        'given_name' => 'Mario',
        'family_name' => 'Rossi',
        'email' => 'mario.rossi@example.com',
        'preferred_username' => 'mario.rossi',
        'locale' => 'it',
        'zoneinfo' => 'Europe/Rome',
        'realm' => 'mock-realm',
        'id' => 'mock-id',
        'enti-codicefiscale' => ['fiscalCode' => self::FISCAL_CODE, 'id' => 'mock-id'],
        'enti-spid' => ['isSpid' => 'true', 'spidCode' => 'MOCK0000000001', 'id' => 'mock-id'],
        'enti-acr' => ['acr' => 'https://www.spid.gov.it/SpidL2', 'id' => 'mock-id'],
        'enti-issuersource' => ['issuerSource' => 'https://idp.mock.invalid', 'id' => 'mock-id'],
    ];

    public function __construct()
    {
        parent::__construct('https://aac.mock.invalid', 'mock-client-id', 'mock-client-secret', 0);
    }

    /**
     * @param  array<string, mixed>  $claims  replaces top-level userinfo claims
     */
    public function withUserInfo(array $claims): static
    {
        $this->userInfo = array_replace($this->userInfo, $claims);

        return $this;
    }

    public function authenticateWith(array $parameters): bool
    {
        $this->setAccessToken(self::ACCESS_TOKEN);

        return true;
    }

    public function authorizationRedirect(): RedirectResponse
    {
        return new RedirectResponse('https://aac.mock.invalid/authorize');
    }

    public function getRefreshToken(): string
    {
        return self::REFRESH_TOKEN;
    }

    public function getIdToken(): string
    {
        return self::ID_TOKEN;
    }

    public function getTokenResponse(): object
    {
        return (object) [
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
            'id_token' => self::ID_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function refreshToken(string $refresh_token): object
    {
        return (object) [
            'access_token' => 'mock-refreshed-access-token',
            'refresh_token' => $refresh_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function requestUserInfo(?string $attribute = null): mixed
    {
        return $attribute === null ? (object) $this->userInfo : ($this->userInfo[$attribute] ?? null);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `composer test`
Expected: PASS (remaining todos only).

- [ ] **Step 7: Commit**

```bash
git add src/SpidTrentinoUser.php tests/Unit/SpidTrentinoUserTest.php tests/Feature/SpidTrentinoServiceTest.php
git commit -m "fix: map the email claim and tolerate unexpected claim types in SpidTrentinoUser"
git add src/Events tests/Unit/EventsTest.php
git commit -m "fix: drop SerializesModels from SPID events" -m "The payload is a plain DTO, not an Eloquent model. The events no longer depend on Illuminate\Foundation (Dispatchable)."
git add src/Testing/MockOpenIDConnectClient.php tests/Feature/MockOpenIDConnectClientTest.php
git commit -m "fix: align MockOpenIDConnectClient with AAC enti-* claims and the Laravel client"
```

---

### Task 11: Publishable migration and user persistence

**Files:**
- Create: `database/migrations/add_spid_trentino_columns_to_users_table.php`, `tests/Feature/AuthenticateFromSpidTest.php`, `tests/Fixtures/NotAuthenticatable.php`
- Modify: `src/SpidTrentinoServiceProvider.php` (migration publish tag), `src/Traits/SpidAuthenticatesUsers.php` (final below), `tests/TestCase.php` (load package migration), remove the `->todo()` markers added in Tasks 8 and 10

- [ ] **Step 1: Write the failing tests**

`tests/Fixtures/NotAuthenticatable.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class NotAuthenticatable extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
```

`tests/Feature/AuthenticateFromSpidTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Testing\MockOpenIDConnectClient;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\NotAuthenticatable;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;

function loginWithSpid(array $claims = []): void
{
    app()->instance(LaravelOpenIDConnectClient::class, (new MockOpenIDConnectClient)->withUserInfo($claims));
    app()->forgetScopedInstances();
    test()->get('/spid/callback');
}

it('creates the user on first login', function () {
    loginWithSpid();

    $user = User::query()->sole();
    expect($user->fiscal_code)->toBe(MockOpenIDConnectClient::FISCAL_CODE)
        ->and($user->name)->toBe('Mario')
        ->and($user->surname)->toBe('Rossi')
        ->and($user->email)->toBe('mario.rossi@example.com')
        ->and($user->preferred_username)->toBe('mario.rossi')
        ->and($user->locale)->toBe('it')
        ->and($user->zoneinfo)->toBe('Europe/Rome')
        ->and($user->password)->toBeNull()
        ->and($user->spid_profile['enti-codicefiscale']['fiscalCode'])->toBe(MockOpenIDConnectClient::FISCAL_CODE);
    $this->assertAuthenticatedAs($user);
});

it('updates the same user on later logins', function () {
    loginWithSpid();
    auth()->logout();

    loginWithSpid(['family_name' => 'Bianchi']);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->surname)->toBe('Bianchi');
});

it('does not take over an email that belongs to another account', function () {
    $other = User::query()->forceCreate(['name' => 'Local', 'email' => 'mario.rossi@example.com', 'password' => 'secret']);

    loginWithSpid();

    $spidUser = User::query()->where('fiscal_code', MockOpenIDConnectClient::FISCAL_CODE)->sole();
    expect($spidUser->is($other))->toBeFalse()
        ->and($spidUser->email)->toBeNull()
        ->and($other->fresh()->email)->toBe('mario.rossi@example.com');
});

it('stores no email when AAC sends none', function () {
    loginWithSpid(['email' => '']);

    expect(User::query()->sole()->email)->toBeNull();
});

it('fails loudly when the configured user model is not an authenticatable Eloquent model', function (string $model) {
    config()->set('auth.providers.users.model', $model);
    $this->withoutExceptionHandling();

    expect(fn () => loginWithSpid())->toThrow(LogicException::class);
})->with([stdClass::class, NotAuthenticatable::class]);

it('publishes the migration under spid-laravel-trentino-migrations', function () {
    $paths = ServiceProvider::pathsToPublish(SpidTrentinoServiceProvider::class, 'spid-laravel-trentino-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('database/migrations')
        ->and(array_values($paths)[0])->toBe(database_path('migrations'));
});

it('rolls the migration back', function () {
    $migration = require __DIR__.'/../../database/migrations/add_spid_trentino_columns_to_users_table.php';

    $migration->down();

    foreach (['fiscal_code', 'surname', 'preferred_username', 'locale', 'zoneinfo', 'spid_profile'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeFalse();
    }
});
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/AuthenticateFromSpidTest.php`
Expected: FAIL with `no such column: fiscal_code` (no migration shipped), the migration tag is empty, and the migration file is missing.

- [ ] **Step 3: Migration**

`database/migrations/add_spid_trentino_columns_to_users_table.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('fiscal_code')->nullable()->unique();
            $table->string('surname')->nullable();
            $table->string('preferred_username')->nullable();
            $table->string('locale')->nullable();
            $table->string('zoneinfo')->nullable();
            $table->json('spid_profile')->nullable();

            // SPID users have no local password and may have no email.
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_code']);
            $table->dropColumn(['fiscal_code', 'surname', 'preferred_username', 'locale', 'zoneinfo', 'spid_profile']);
        });
    }
};
```

In `SpidTrentinoServiceProvider::boot()`, inside `runningInConsole()`:
```php
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'spid-laravel-trentino-migrations');
```

In `tests/TestCase.php` `defineDatabaseMigrations()` add:
```php
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
```

- [ ] **Step 4: Final trait**

`src/Traits/SpidAuthenticatesUsers.php`:
```php
<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClientException;
use LogicException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

trait SpidAuthenticatesUsers
{
    /**
     * Finds the local user by fiscal code (or creates it), syncs the profile
     * and logs it in. The model needs the columns of the package migration,
     * those attributes in $fillable and 'spid_profile' => 'array' in $casts.
     */
    protected function authenticateFromSpid(SpidTrentinoUser $spidUser): void
    {
        $userModel = Config::string('auth.providers.users.model');

        if (! is_a($userModel, Model::class, true)) {
            throw new LogicException("The user model [{$userModel}] must be an Eloquent model.");
        }

        $user = $userModel::query()->firstOrNew(['fiscal_code' => $spidUser->getFiscalNumber()]);

        if (! $user instanceof Authenticatable) {
            throw new LogicException("The user model [{$userModel}] must implement Authenticatable.");
        }

        $user->fill([
            'name' => $spidUser->getName(),
            'surname' => $spidUser->getSurname(),
            'preferred_username' => $spidUser->getPreferredUsername(),
            'locale' => $spidUser->getLocale(),
            'zoneinfo' => $spidUser->getZoneinfo(),
        ]);

        $email = $spidUser->getEmail();

        // Never link or take over another account by email.
        if ($email !== '' && ! $userModel::query()
            ->where('email', $email)
            ->when($user->exists, fn (Builder $query) => $query->whereKeyNot($user->getKey()))
            ->exists()) {
            $user->setAttribute('email', $email);
        }

        $user->setAttribute('spid_profile', $spidUser->toArray());
        $user->save();

        Auth::login($user);
        Session::regenerate();

        Log::info('[SPID] Local user authenticated', ['user_id' => $user->getKey()]);
    }

    /**
     * Logs the failure and sends the user to error_redirect_to with a flash message.
     */
    protected function spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse
    {
        Log::error('[SPID] Authentication failed', ['exception' => $exception]);

        return Redirect::to(Config::string('spid-laravel-trentino.error_redirect_to'))
            ->with(SessionKeys::ERROR, 'SPID authentication failed. Please try again.');
    }

    protected function redirectTo(): string
    {
        $configured = Config::get('spid-laravel-trentino.redirect_to');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $user = Auth::user();

        if ($user !== null && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return '/admin/dashboard';
        }

        return '/';
    }
}
```

- [ ] **Step 5: Remove the `->todo()` markers** from `AuthControllerTest` (two tests) and `MockOpenIDConnectClientTest` (one test).

- [ ] **Step 6: Run the tests**

Run: `composer test`
Expected: all PASS, no todos. If `->change()` on SQLite drops the `email` unique index and breaks the collision test, debug with superpowers:systematic-debugging. The production behavior must stay "no takeover".

- [ ] **Step 7: Commit**

```bash
git add database src/SpidTrentinoServiceProvider.php tests/TestCase.php tests/Feature/AuthenticateFromSpidTest.php tests/Fixtures/NotAuthenticatable.php
git commit -m "feat: ship a publishable migration for the SPID user columns"
git add src/Traits/SpidAuthenticatesUsers.php tests/Feature/AuthControllerTest.php tests/Feature/MockOpenIDConnectClientTest.php
git commit -m "fix: persist SPID users with email, without taking over existing accounts" -m "Validates the configured user model, maps email only when no other user owns it, and uses getZoneinfo() consistently."
```

---

### Task 12: Blade login button, remove the Vue component

**Files:**
- Modify: `resources/views/components/login-button.blade.php`
- Delete: `resources/js/AacLoginButton.vue`
- Create: `tests/Feature/LoginButtonTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the SPID login button linking to the login route', function () {
    $html = Blade::render('<x-spid-laravel-trentino::login-button />');

    expect($html)->toContain('href="'.route('spid.login').'"')
        ->toContain('Entra con SPID')
        ->toContain('class="btn btn-light-primary align-self-center w-100"');
});

it('accepts a custom label and extra classes', function () {
    $html = Blade::render('<x-spid-laravel-trentino::login-button label="Accedi" class="mt-4" />');

    expect($html)->toContain('Accedi')->toContain('w-100 mt-4"');
});
```

Run: `vendor/bin/pest tests/Feature/LoginButtonTest.php`
Expected: FAIL. The href is the relative config path, the label is "Accedi con AAC", and the class has a stray `"`. If the anonymous component does not resolve at all, register it explicitly in `boot()` with `Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'spid-laravel-trentino');` (`Illuminate\Support\Facades\Blade`) and re-run.

- [ ] **Step 2: Implementation**

`resources/views/components/login-button.blade.php`:
```blade
@props(['label' => 'Entra con SPID'])

<a href="{{ route('spid.login') }}" {{ $attributes->merge(['class' => 'btn btn-light-primary align-self-center w-100']) }}>
    {{ $label }}
</a>
```
Then: `git rm -r resources/js` (and `git rm --cached resources/.DS_Store` if tracked).

- [ ] **Step 3: Run and commit**

Run: `composer test`. Expected: PASS.
```bash
git add -A resources tests/Feature/LoginButtonTest.php
git commit -m "fix: link the Blade login button to spid.login and label it SPID" -m "Removes the stray quote in the class attribute and the unused AacLoginButton.vue component."
```

---

# Phase 3: Quality gates

### Task 13: Foundation-free source, PHPStan max, 100% coverage, mutation score

**Files:**
- Modify: `tests/Arch/ArchTest.php`, any `src/` file flagged by PHPStan, new tests for uncovered lines

- [ ] **Step 1: Add the foundation-free architecture rule**

Append to `tests/Arch/ArchTest.php`:
```php
arch('src depends on illuminate components, not on the framework')
    ->expect('OfflineAgency\SpidLaravelTrentino')
    ->not->toUse([
        'Illuminate\Foundation',
        'app', 'auth', 'config', 'config_path', 'database_path', 'event', 'logger',
        'now', 'redirect', 'request', 'resource_path', 'response', 'route', 'session', 'url', '__',
    ])
    ->ignoring('OfflineAgency\SpidLaravelTrentino\Tests');
```
Run: `vendor/bin/pest tests/Arch`. Expected: PASS. If it fails, replace each reported helper with its facade.

- [ ] **Step 2: Static analysis**

Run: `composer analyse`
Expected: `[OK] No errors`. Fix every finding in code; never add a baseline or `ignoreErrors`. If level `max` cannot be reached without unsound casts, lower `level` one step at a time and record the reached level and the blocking errors for the final report and the README badge.

- [ ] **Step 3: Coverage**

Run: `composer test-coverage` (or the Docker command from Task 1, Step 8)
Expected: `Total: 100.0 %`. For every uncovered line, write a test that exercises the behavior. Use `@codeCoverageIgnore` only for lines that cannot run, and record each one (file, line, reason).

- [ ] **Step 4: Mutation testing**

Add `mutates(...)` at the top of the main test file of each core class not yet covered: `LaravelOpenIDConnectClient` (LaravelOpenIDConnectClientTest), `SessionExpiry`, `TokenResponse`, `LogRedactor` (their unit tests), `EnsureValidSpidToken`, `RefreshSpidTokenIfNeeded`, the trait (AuthenticateFromSpidTest).
Run: `composer mutate -- --parallel`
Expected: a mutation score is printed. Record it, and add tests for escaped mutants that reveal a real gap. There is no hard threshold.

- [ ] **Step 5: Commit**

```bash
git add -A tests src
git commit -m "test: reach 100% line coverage and enforce a foundation-free src"
```
If PHPStan fixes are substantial, commit them separately as `refactor: satisfy PHPStan level max`.

---

### Task 14: Run the full matrix locally and set version floors

**Files:**
- Modify: `composer.json` (floors only, if needed)

- [ ] **Step 1: Run every combination in a scratch copy**

```bash
SCRATCH=/private/tmp/claude-501/-Users-gfabbian-projects-spid-laravel-trentino/c2c00409-0949-4018-b3cc-c71d11e3a307/scratchpad/matrix
HERD="/Users/gfabbian/Library/Application Support/Herd/bin"
COMPOSER=$(which composer)
for php in 84 85; do for l in 12 13; do for s in prefer-lowest prefer-stable; do
  dir="$SCRATCH/php$php-l$l-$s"; rm -rf "$dir"; mkdir -p "$dir"
  rsync -a --exclude vendor --exclude .git --exclude composer.lock ./ "$dir"/
  tb=$([ $l = 12 ] && echo '10.*' || echo '11.*'); pest=$([ $l = 12 ] && echo '4.*' || echo '5.*')
  ( cd "$dir" && "$HERD/php$php" "$COMPOSER" require "laravel/framework:$l.*" "orchestra/testbench:$tb" "pestphp/pest:$pest" --dev --no-update -q \
    && "$HERD/php$php" "$COMPOSER" update --$s --prefer-dist -q --no-interaction \
    && echo "== php$php laravel$l $s: $("$HERD/php$php" vendor/bin/pest --compact 2>&1 | tail -n 3 | tr '\n' ' ')" ) || echo "== php$php laravel$l $s: INSTALL FAILED"
done; done; done
```
Expected: eight lines, each reporting `Tests: N passed`.

- [ ] **Step 2: Set floors**

For each prefer-lowest run, read the installed versions: `cd $dir && composer show laravel/framework orchestra/testbench pestphp/pest`. Set the `illuminate/*` floors to the lowest Laravel 12 version that installed and passed (for example `^12.69|^13.0`), and the Laravel 13 floor likewise if it is above `13.0`. Re-run the two prefer-lowest legs after changing the floors.

- [ ] **Step 3: Commit (only if floors changed)**

```bash
git add composer.json && git commit -m "chore(deps): raise illuminate floors to the lowest tested releases"
```

---

# Phase 4: CI and automatic releases

### Task 15: Tests workflow, PR-title check, Dependabot and templates

**Files:**
- Create: `.github/workflows/tests.yml`, `.github/workflows/pr-title.yml`, `.github/scripts/check-pr-title.sh`, `.github/dependabot.yml`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/bug_report.yml`, `.github/ISSUE_TEMPLATE/feature_request.yml`, `.github/ISSUE_TEMPLATE/config.yml`
- Delete: `.github/workflows/test.yml`

- [ ] **Step 1: Check the current major versions of the actions**

```bash
for a in actions/checkout shivammathur/setup-php codecov/codecov-action; do echo "$a $(gh api repos/$a/releases/latest --jq .tag_name)"; done
```
Use those majors (for example `@v5`) in the YAML below, where `vX` stands for the major you just read.

- [ ] **Step 2: `.github/workflows/tests.yml`**

```yaml
name: Tests

on:
  push:
    branches: [master]
  pull_request:
    branches: [master]

concurrency:
  group: tests-${{ github.ref }}
  cancel-in-progress: true

permissions:
  contents: read

jobs:
  tests:
    name: PHP ${{ matrix.php }} / Laravel ${{ matrix.laravel }} / ${{ matrix.stability }}
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.4', '8.5']
        laravel: ['12', '13']
        stability: [prefer-lowest, prefer-stable]
        include:
          - laravel: '12'
            testbench: '10.*'
            pest: '4.*'
          - laravel: '13'
            testbench: '11.*'
            pest: '5.*'
    steps:
      - uses: actions/checkout@vX
      - uses: shivammathur/setup-php@vX
        with:
          php-version: ${{ matrix.php }}
          extensions: curl, mbstring, openssl, pdo_sqlite, sqlite3
          coverage: none
      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}.*" "orchestra/testbench:${{ matrix.testbench }}" "pestphp/pest:${{ matrix.pest }}" --dev --no-update --no-interaction
          composer update --${{ matrix.stability }} --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pest --ci

  coverage:
    name: Coverage
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@vX
      - uses: shivammathur/setup-php@vX
        with:
          php-version: '8.5'
          extensions: curl, mbstring, openssl, pdo_sqlite, sqlite3
          coverage: pcov
      - run: composer update --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pest --ci --coverage --min=100 --coverage-clover build/coverage.xml
      - uses: codecov/codecov-action@vX
        with:
          files: build/coverage.xml
          token: ${{ secrets.CODECOV_TOKEN }}
          fail_ci_if_error: false

  static-analysis:
    name: PHPStan
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@vX
      - uses: shivammathur/setup-php@vX
        with:
          php-version: '8.5'
          coverage: none
      - run: composer update --prefer-dist --no-interaction --no-progress
      - run: composer analyse -- --no-progress --error-format=github

  code-style:
    name: Pint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@vX
      - uses: shivammathur/setup-php@vX
        with:
          php-version: '8.5'
          coverage: none
      - run: composer update --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pint --test

  release-scripts:
    name: Release scripts
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@vX
      - run: bash .github/scripts/next-version.test.sh
```
(The `release-scripts` job depends on Task 16. Commit it with Task 16 if Task 15 is committed first.)

All combinations are supported: Laravel 12 and 13 both run on PHP 8.4 and 8.5. The workflow therefore needs no `exclude`. Add one only if Task 14 finds an incompatible pair.

- [ ] **Step 3: PR-title check**

`.github/scripts/check-pr-title.sh`:
```bash
#!/usr/bin/env bash
# Fails unless PR_TITLE is a Conventional Commit header, e.g. "feat(auth)!: drop Laravel 11".
set -euo pipefail

pattern='^(build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test)(\([a-z0-9._/-]+\))?!?: .+'

if [[ "${PR_TITLE:-}" =~ $pattern ]]; then
  echo "PR title OK"
else
  echo "PR title must follow Conventional Commits (<type>[(scope)][!]: <description>). Got: ${PR_TITLE:-<empty>}" >&2
  exit 1
fi
```

`.github/workflows/pr-title.yml`:
```yaml
name: PR title

on:
  pull_request:
    types: [opened, edited, synchronize, reopened]
    branches: [master]

permissions:
  contents: read

jobs:
  conventional-commit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@vX
      - name: Check the title follows Conventional Commits
        env:
          PR_TITLE: ${{ github.event.pull_request.title }}
        run: bash .github/scripts/check-pr-title.sh
```
The title is passed through `env`, never interpolated into the script, to prevent script injection.

Run locally:
```bash
PR_TITLE='feat(auth)!: require PHP 8.4' bash .github/scripts/check-pr-title.sh
PR_TITLE='Update stuff' bash .github/scripts/check-pr-title.sh; echo "exit=$?"
```
Expected: `PR title OK`, then the error message and `exit=1`.

- [ ] **Step 4: Dependabot and templates**

`.github/dependabot.yml`:
```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /
    schedule:
      interval: weekly
    commit-message:
      prefix: chore(deps)
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
    commit-message:
      prefix: ci
```

`.github/PULL_REQUEST_TEMPLATE.md`:
```markdown
## Summary

<!-- What does this change and why? -->

## Release

The PR title must follow [Conventional Commits](https://www.conventionalcommits.org/). It decides the next version when the PR is merged:

- `feat!: ...`, `fix!: ...` or a `BREAKING CHANGE:` line in this description: major
- `feat: ...`: minor
- anything else: patch

Add the `skip-release` label to merge without releasing.

## Checklist

- [ ] Tests added or updated (`composer test-coverage` stays at 100%)
- [ ] `composer analyse` and `composer format-check` pass
- [ ] README, UPGRADE and CHANGELOG updated when behavior changes
```

`.github/ISSUE_TEMPLATE/bug_report.yml`:
```yaml
name: Bug report
description: Something does not work as documented
labels: [bug]
body:
  - type: markdown
    attributes:
      value: Do not report security vulnerabilities here; see SECURITY.md.
  - type: input
    id: versions
    attributes:
      label: Versions
      description: Package, PHP and Laravel versions
      placeholder: "spid-laravel-trentino 2.0.0, PHP 8.4.x, Laravel 13.x"
    validations:
      required: true
  - type: textarea
    id: steps
    attributes:
      label: Steps to reproduce
    validations:
      required: true
  - type: textarea
    id: expected
    attributes:
      label: Expected and actual behavior
    validations:
      required: true
  - type: textarea
    id: logs
    attributes:
      label: Relevant logs
      description: Remove tokens, fiscal codes and other personal data before pasting.
      render: shell
```

`.github/ISSUE_TEMPLATE/feature_request.yml`:
```yaml
name: Feature request
description: Suggest an improvement
labels: [enhancement]
body:
  - type: textarea
    id: problem
    attributes:
      label: Problem
      description: What are you trying to do, and what gets in the way?
    validations:
      required: true
  - type: textarea
    id: proposal
    attributes:
      label: Proposed solution
    validations:
      required: true
  - type: textarea
    id: alternatives
    attributes:
      label: Alternatives considered
```

`.github/ISSUE_TEMPLATE/config.yml`:
```yaml
blank_issues_enabled: false
contact_links:
  - name: Security vulnerability
    url: https://github.com/offline-agency/spid-laravel-trentino/security/policy
    about: Report vulnerabilities privately, not in public issues.
```

- [ ] **Step 5: Validate YAML**

Run: `command -v actionlint && actionlint || php -r 'foreach (glob(".github/{workflows,ISSUE_TEMPLATE}/*.yml", GLOB_BRACE) + [".github/dependabot.yml"] as $f) { echo $f, ": ", (yaml_parse_file($f) === false ? "INVALID" : "ok"), "\n"; }'`
If neither actionlint nor ext-yaml is available, use `ruby -ryaml -e 'ARGV.each { |f| YAML.load_file(f); puts "#{f}: ok" }' .github/workflows/*.yml .github/ISSUE_TEMPLATE/*.yml .github/dependabot.yml`.
Expected: every file `ok`.

- [ ] **Step 6: Commit**

```bash
git rm -q .github/workflows/test.yml
git add .github/workflows/tests.yml && git commit -m "ci: test PHP 8.4/8.5 x Laravel 12/13 at lowest and stable versions, plus coverage, PHPStan and Pint jobs"
git add .github/workflows/pr-title.yml .github/scripts/check-pr-title.sh && git commit -m "ci: require Conventional Commit PR titles"
git add .github/dependabot.yml && git commit -m "ci: add Dependabot for composer and GitHub Actions"
git add .github/PULL_REQUEST_TEMPLATE.md .github/ISSUE_TEMPLATE && git commit -m "docs: add pull request and issue templates"
```

---

### Task 16: Release workflow

**Files:**
- Create: `.github/scripts/next-version.sh`, `.github/scripts/next-version.test.sh`, `.github/workflows/release.yml`

**Interfaces:**
- Produces: `next-version.sh <latest-tag-or-empty>` reading `PR_TITLE`, `PR_BODY`, `INITIAL_VERSION` (default `v2.0.0`) from the environment and printing the next tag.

- [ ] **Step 1: Failing script test**

`.github/scripts/next-version.test.sh`:
```bash
#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")"

failures=0
check() { # expected latest title [body]
  local actual
  actual="$(PR_TITLE="$3" PR_BODY="${4:-}" bash ./next-version.sh "$2" 2>&1)"
  if [[ "$actual" == "$1" ]]; then echo "ok   $1 <= '$2' '$3'"; else echo "FAIL expected $1, got '$actual' <= '$2' '$3'"; failures=$((failures + 1)); fi
}

check v2.0.0 ""       "fix: anything"
check v2.0.1 v2.0.0   "fix: handle empty claims"
check v2.0.1 v2.0.0   "chore(deps): bump jumbojett"
check v2.0.1 v2.0.0   "Not a conventional title"
check v2.1.0 v2.0.1   "feat: add back-channel logout"
check v2.1.0 v2.0.1   "feat(routes): configurable prefix"
check v3.0.0 v2.1.0   "feat!: drop Laravel 12"
check v3.0.0 v2.1.0   "fix(auth)!: change session keys"
check v3.0.0 v2.1.0   "refactor: rename config" $'Some text\n\nBREAKING CHANGE: config key renamed'
check v2.1.1 v2.1.0   "docs: mention BREAKING CHANGE in passing" "no footer here"

if PR_TITLE="fix: x" bash ./next-version.sh "release-2" >/dev/null 2>&1; then echo "FAIL accepted a malformed tag"; failures=$((failures + 1)); else echo "ok   rejects malformed tag"; fi

exit "$failures"
```

Run: `bash .github/scripts/next-version.test.sh`
Expected: FAIL (`next-version.sh` missing).

- [ ] **Step 2: `.github/scripts/next-version.sh`**

```bash
#!/usr/bin/env bash
# Prints the next release tag from the latest vX.Y.Z tag and the merged PR.
# Usage: PR_TITLE=... PR_BODY=... next-version.sh "<latest tag or empty>"
#   <type>!: or a "BREAKING CHANGE:" footer -> major
#   feat:                                    -> minor
#   anything else                            -> patch
set -euo pipefail

latest="${1:-}"
title="${PR_TITLE:-}"
body="${PR_BODY:-}"

if [[ -z "$latest" ]]; then
  echo "${INITIAL_VERSION:-v2.0.0}"
  exit 0
fi

if [[ ! "$latest" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "Unsupported tag format: $latest" >&2
  exit 1
fi
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
patch="${BASH_REMATCH[3]}"

if [[ "$title" =~ ^[a-z]+(\([^\)]*\))?!: ]] || grep -Eq '^BREAKING[ -]CHANGE:' <<<"$body"; then
  echo "v$((major + 1)).0.0"
elif [[ "$title" =~ ^feat(\([^\)]*\))?: ]]; then
  echo "v${major}.$((minor + 1)).0"
else
  echo "v${major}.${minor}.$((patch + 1))"
fi
```

Run: `bash .github/scripts/next-version.test.sh`
Expected: every line `ok`, exit 0.

- [ ] **Step 3: `.github/workflows/release.yml`**

```yaml
name: Release

on:
  pull_request:
    types: [closed]
    branches: [master]

permissions: {}

concurrency:
  group: release
  cancel-in-progress: false

jobs:
  release:
    # Merged PRs only; closed-without-merge PRs and skip-release PRs never release.
    if: github.event.pull_request.merged == true && !contains(github.event.pull_request.labels.*.name, 'skip-release')
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      - uses: actions/checkout@vX
        with:
          ref: ${{ github.event.pull_request.merge_commit_sha }}
          fetch-depth: 0

      - name: Compute the next version
        id: version
        env:
          PR_TITLE: ${{ github.event.pull_request.title }}
          PR_BODY: ${{ github.event.pull_request.body }}
        run: |
          latest="$(git tag --list 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)"
          next="$(bash .github/scripts/next-version.sh "$latest")"
          echo "Latest: ${latest:-none}, next: $next"
          echo "tag=$next" >> "$GITHUB_OUTPUT"

      - name: Create the annotated tag
        env:
          TAG: ${{ steps.version.outputs.tag }}
          SHA: ${{ github.event.pull_request.merge_commit_sha }}
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git tag -a "$TAG" -m "Release $TAG" "$SHA"
          # Pushed with GITHUB_TOKEN: no commit is created and no other workflow is triggered.
          git push origin "refs/tags/$TAG"

      - name: Create the GitHub release
        env:
          GH_TOKEN: ${{ github.token }}
          TAG: ${{ steps.version.outputs.tag }}
        run: gh release create "$TAG" --verify-tag --generate-notes --title "$TAG"
```

- [ ] **Step 4: Validate and commit**

Validate the YAML as in Task 15, Step 5.
```bash
git add .github/scripts/next-version.sh .github/scripts/next-version.test.sh && git commit -m "ci: compute the next SemVer tag from Conventional Commit PR titles"
git add .github/workflows/release.yml && git commit -m "ci: tag and release automatically when a PR is merged into master"
```
If the `release-scripts` job was not part of the Task 15 commit, commit it now with `ci: run the release script tests`.

---

# Phase 5: Documentation and reuse metadata

### Task 17: README, SECURITY, CONTRIBUTING, UPGRADE, CHANGELOG

**Files:**
- Modify: `README.md` (rewrite), `CONTRIBUTING.md`, `CHANGELOG.md` (rewrite)
- Create: `SECURITY.md`, `UPGRADE.md`

- [ ] **Step 1: Verify external facts**

```bash
curl -sSIL -o /dev/null -w '%{http_code} %{url_effective}\n' https://www.comunitrentini.it/
```
Expected: `200`. If it isn't, report it and ask the maintainer before committing the link.

- [ ] **Step 2: Write `README.md`** with exactly these sections, in this order. Every code snippet must be copied from, or checked against, the final code:
  1. Title and badges: `https://img.shields.io/packagist/v/offline-agency/spid-laravel-trentino`, `.../packagist/dt/...`, `.../packagist/dependency-v/offline-agency/spid-laravel-trentino/php`, `.../packagist/dependency-v/offline-agency/spid-laravel-trentino/illuminate%2Fsupport?label=laravel`, `https://github.com/offline-agency/spid-laravel-trentino/actions/workflows/tests.yml/badge.svg?branch=master`, `https://codecov.io/gh/offline-agency/spid-laravel-trentino/graph/badge.svg`, `https://img.shields.io/badge/PHPStan-level%20max-brightgreen` (use the level reached in Task 13), `https://img.shields.io/packagist/l/offline-agency/spid-laravel-trentino`. Each badge links to Packagist, the workflow, Codecov or LICENSE.md.
  2. Description: two sentences (SPID login for Laravel through AAC Trentino, OIDC authorization code flow with PKCE).
  3. Funding and reuse: "This package was developed with funding from the [Consorzio dei Comuni Trentini](https://www.comunitrentini.it/) and is released as open source software for reuse by public administrations and other parties, in line with art. 69 of the Italian CAD (Codice dell'Amministrazione Digitale)."
  4. Requirements table: PHP 8.4, 8.5; Laravel 12, 13; plus a "Tested matrix" line.
  5. Installation: `composer require offline-agency/spid-laravel-trentino`; publish commands for `--tag=spid-laravel-trentino-config`, `--tag=spid-laravel-trentino-migrations` (then `php artisan migrate`), and `--tag=spid-laravel-trentino-views`; the `.env` block from `.env.example`; AAC client registration (the redirect URI must equal `SPID_TRENTINO_REDIRECT_URI`, or the absolute URL of `/spid/callback` when empty; the client needs the `openid profile.codicefiscale.me email offline_access` scopes).
  6. Configuration reference: a table with every key from `config/spid-laravel-trentino.php` (key, default, description).
  7. Routes table (method, path, name, purpose) and the `register_routes` option.
  8. Middleware: aliases registered automatically; example `Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->group(...)` with the ordering explanation; JSON 419 behavior; Laravel 11+ manual alternative in `bootstrap/app.php` (`->withMiddleware(fn (Middleware $middleware) => $middleware->alias([...]))`) for apps that disable package discovery.
  9. User model: required columns, `$fillable`, and `casts(): ['spid_profile' => 'array']` snippet; the email rule (never takes over another account's email); customizing by extending `SpidAuthController` (snippet overriding `authenticateFromSpid()` and calling `parent::`) and pointing `auth_controller` at it.
  10. Login button: `<x-spid-laravel-trentino::login-button />` and `label`/`class` usage.
  11. Session keys: table generated from `SessionKeys` (constant, key, content).
  12. Events: `SpidTrentinoLoggedIn`, `SpidTrentinoLoggedOut` with an `Event::listen` snippet using `$event->user`.
  13. Token refresh and error handling: refresh window 60 s; failure forgets tokens; `error_redirect_to` + `session(SessionKeys::ERROR)`; ID token verification done by jumbojett (signature from discovered `jwks_uri`, iss, aud, sub, nonce, exp, nbf, at_hash) plus the package's stricter checks; discovery/JWKS cache (`cache_ttl`, one forced refresh on unknown key).
  14. Logging and privacy: no tokens or personal data in logs; `LogRedactor` keyed hashes.
  15. Testing: `composer test`, `composer test-coverage`, `composer analyse`, `composer format`, `composer mutate`; using `MockOpenIDConnectClient` in an app test (snippet with `$this->app->instance(LaravelOpenIDConnectClient::class, (new MockOpenIDConnectClient)->withUserInfo([...]))`, `get('/spid/callback')`, `assertAuthenticated()`).
  16. Release process: Conventional Commit PR titles to version mapping, `skip-release`, first release `v2.0.0`; Packagist webhook verification (Packagist package page shows "auto-updated"; GitHub repo Settings, Webhooks lists `https://packagist.org/api/github` with a green check on the last delivery).
  17. Upgrading: link to UPGRADE.md. Changelog: link. Contributing: link. Security: link to SECURITY.md. Credits: Giacomo Fabbian and `https://github.com/offline-agency/spid-laravel-trentino/graphs/contributors`. About us. License.

- [ ] **Step 3: `SECURITY.md`**

```markdown
# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.x     | Yes       |
| < 2.0   | No        |

## Reporting a vulnerability

Please do not open public issues for security problems. Use GitHub's private vulnerability reporting (Security tab, "Report a vulnerability") or email support@offlineagency.it.

Include the affected version, a description of the issue and, if possible, steps to reproduce. Never include real tokens, fiscal codes or other personal data. We will acknowledge the report within five working days.
```

- [ ] **Step 4: `CONTRIBUTING.md`**: replace the PSR-2 bullet with:
```markdown
- **Coding style**: PSR-12 as enforced by [Laravel Pint](https://laravel.com/docs/pint). Run `composer format` before committing; CI runs `composer format-check`.
- **Static analysis**: `composer analyse` must pass without a baseline.
- **Tests**: `composer test-coverage` must stay at 100% line coverage.
- **Pull request titles** follow [Conventional Commits](https://www.conventionalcommits.org/); the title decides the next release version.
```
Also replace "squash them" link text and all em dashes with commas or parentheses.

- [ ] **Step 5: `UPGRADE.md`**: "Upgrading from 1.x to 2.0". Each item is a heading plus before/after snippets:
  - PHP 8.4+, Laravel 12/13; `laravel/framework` 10/11 no longer supported.
  - Config file renamed to `config/spid-laravel-trentino.php`, tag `spid-laravel-trentino-config`. Republish, or rename `config/spid.php`, and add the new keys `cache_ttl`, `register_routes`, `logout_redirect_to`, `error_redirect_to`. `redirect_to` now actually applies and defaults to null (1.x shipped `/dashboard` but never read it).
  - Logout route moved from `POST /logout` to `POST /spid/logout` (route name `spid.logout` unchanged).
  - Login route now handled by `SpidAuthController@login`. Custom controllers that extend the base controller inherit it; others must add `login(SpidTrentino $spid)`.
  - Session keys renamed (table: `access_token` to `spid_trentino_access_token`, `refresh_token` to `spid_trentino_refresh_token`, `access_token_expires_at` to `spid_trentino_access_token_expires_at`, now an ISO-8601 string); use `SessionKeys` constants. Users logged in under 1.x must log in again.
  - Container: `spid-laravel-trentino.auth` binding removed; resolve `SpidTrentino::class` or use the facade. `SpidTrentino` is request-scoped and its constructor takes `LaravelOpenIDConnectClient`.
  - `SpidTrentino::redirectToLogin()` returns a `RedirectResponse` (was `bool` + `exit`); `handleCallback()` returns `SpidTrentinoUser`.
  - `SpidAuthenticatesUsers::spidLogout()` removed; call `SpidTrentino::logout()` (dispatches `SpidTrentinoLoggedOut`).
  - Callback failures redirect to `error_redirect_to` with a flash message instead of throwing.
  - Callback fails when AAC returns no fiscal code.
  - Events: `setUser()` removed, `$user` is readonly; `Dispatchable` and `SerializesModels` removed (dispatch with `event(new SpidTrentinoLoggedIn($user))`).
  - `MockOpenIDConnectClient` now extends `LaravelOpenIDConnectClient`, uses `enti-codicefiscale` and is bound with `$this->app->instance(LaravelOpenIDConnectClient::class, new MockOpenIDConnectClient)`.
  - New migration: publish and run it; `email` and `password` become nullable.
  - `firebase/php-jwt` no longer installed by this package (require it yourself if you used it).
  - Vue component removed; Blade button label is now "Entra con SPID".
  - Middleware order: put `spid.refresh` before `spid.valid`; aliases are registered automatically (remove them from `Kernel.php`/`bootstrap/app.php`).

- [ ] **Step 6: `CHANGELOG.md`** (Keep a Changelog)

```markdown
# Changelog

All notable changes to `offline-agency/spid-laravel-trentino` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From 2.0.0 on, GitHub Releases carry auto-generated notes for every release.

## [2.0.0] - Unreleased

See [UPGRADE.md](UPGRADE.md) for the breaking changes.

### Added
- (one line per feature commit of this branch: LaravelOpenIDConnectClient, SessionKeys, SessionExpiry, TokenResponse, LogRedactor, publishable migration and views, middleware aliases, register_routes, error_redirect_to, logout_redirect_to, cache_ttl, email claim, CI, automatic releases, publiccode.yml)

### Changed
- (PHP/Laravel matrix, jumbojett 1.x, config file and tag, logout route, session keys, container bindings, return types, events)

### Removed
- (laravel/framework requirement, firebase/php-jwt requirement, spidLogout(), Vue component, StyleCI)

### Fixed
- (one line per `fix:` commit of this branch)

### Security
- Tokens, client secret and personal data are no longer written to logs.
- ID tokens without `exp` or with a foreign `aud` are rejected.

## [1.0.0-dev] - 2025-06-17 (never tagged)

### Added
- First implementation (2025-06-08): SPID login through AAC Trentino with OIDC and PKCE, session storage, login and logout events, `spid.valid` and `spid.refresh` middleware, configurable controller, Blade login button.

### Changed
- 2025-06-17: moved the login handling from a listener to `SpidAuthController`; README rewrite.

### Fixed
- 2025-06-17: claim mapping, config path, session persistence in the callback middleware.
```
Fill the bracketed lines from `git log master..HEAD --format=%s`, one bullet per commit, in plain English. Leave no "(...)" placeholders.

- [ ] **Step 7: Verify README snippets and style**

```bash
grep -n '—' README.md CHANGELOG.md UPGRADE.md SECURITY.md CONTRIBUTING.md; echo "em-dash check exit=$? (1 means none found)"
grep -on "spid-laravel-trentino-[a-z]*" README.md UPGRADE.md | sort -u
grep -oE "SessionKeys::[A-Z_]+" README.md | sort -u
```
Expected: no em dashes; only the three real tags; only constants that exist in `src/SessionKeys.php`. Paste the README `MockOpenIDConnectClient` test snippet into a temporary `tests/Feature/ReadmeSnippetTest.php`, run it, then delete the file. It must pass as written.

- [ ] **Step 8: Commit**

```bash
git add README.md && git commit -m "docs: rewrite README for 2.0"
git add SECURITY.md CONTRIBUTING.md && git commit -m "docs: add security policy and update contributing guide for Pint"
git add UPGRADE.md CHANGELOG.md && git commit -m "docs: add 2.0 upgrade guide and real changelog history"
```

---

### Task 18: `publiccode.yml` skeleton

**Files:**
- Create: `publiccode.yml`

- [ ] **Step 1: Write the skeleton**

```yaml
# publiccode.yml for the Developers Italia reuse catalogue.
# Spec: https://yml.publiccode.tools/
publiccodeYmlVersion: "0"

name: SPID Laravel Trentino
url: "https://github.com/offline-agency/spid-laravel-trentino"
landingURL: "https://github.com/offline-agency/spid-laravel-trentino"
# TODO(maintainer): set to the first tagged release once v2.0.0 exists.
softwareVersion: "2.0.0"
# TODO(maintainer): set to the v2.0.0 release date (YYYY-MM-DD).
releaseDate: "2026-10-07"
platforms:
  - web
categories:
  - identity-management
developmentStatus: stable
softwareType: library

description:
  it:
    shortDescription: "Autenticazione SPID per Laravel tramite AAC Trentino (OpenID Connect con PKCE)."
    # TODO(maintainer): write a longDescription of at least 150 characters in Italian.
    longDescription: >
      TODO
    features:
      - Login SPID tramite AAC Trentino con OpenID Connect e PKCE
      - Creazione e aggiornamento automatico degli utenti tramite codice fiscale
      - Middleware per sessione valida e rinnovo automatico del token
      - Eventi di login e logout
  en:
    shortDescription: "SPID authentication for Laravel through AAC Trentino (OpenID Connect with PKCE)."
    # TODO(maintainer): write a longDescription of at least 150 characters.
    longDescription: >
      TODO
    features:
      - SPID login through AAC Trentino with OpenID Connect and PKCE
      - Users created and updated by fiscal code
      - Middleware for valid sessions and automatic token refresh
      - Login and logout events

legal:
  license: MIT
  # TODO(maintainer): confirm the copyright holder (Offline Agency, or the Consorzio dei Comuni Trentini as funder/owner).
  mainCopyrightOwner: Offline Agency
  repoOwner: Offline Agency

maintenance:
  type: contract
  contractors:
    - name: Offline Agency
      # TODO(maintainer): end date of the maintenance contract (YYYY-MM-DD).
      until: "TODO"
      website: "https://offlineagency.it"
  contacts:
    - name: Offline Agency
      email: support@offlineagency.it

localisation:
  localisationReady: false
  availableLanguages:
    - it
    - en

dependsOn:
  open:
    - name: PHP
      versionMin: "8.4"
    - name: Laravel
      versionMin: "12.0"

it:
  countryExtensionVersion: "1.0"
  conforme:
    lineeGuidaDesign: false
    modelloInteroperabilita: false
    misureMinimeSicurezza: false
    gdpr: false
  piattaforme:
    spid: true
    pagopa: false
    cie: false
    anpr: false
    io: false
  # TODO(maintainer): set riuso.codiceIPA to the IPA code of the owning administration (for example the Consorzio dei Comuni Trentini), if the software is released by a PA.
```

- [ ] **Step 2: Validate**

```bash
command -v publiccode-parser && publiccode-parser publiccode.yml \
  || docker run --rm -v "$PWD":/data italia/publiccode-parser-go /data/publiccode.yml \
  || echo "publiccode-parser not available"
```
Expected: the only errors are about the `TODO` values (`longDescription` length, `maintenance.contractors[0].until`). Record the output. If no validator is available, state that in the report.

- [ ] **Step 3: Commit**

```bash
git add publiccode.yml && git commit -m "docs: add publiccode.yml skeleton for the Developers Italia catalogue"
```

---

### Task 19: Final verification, review and branch finish

- [ ] **Step 1: Use superpowers:verification-before-completion.** Run and paste the output of:
```bash
composer validate --strict
composer test-coverage
composer analyse
composer format-check
bash .github/scripts/next-version.test.sh
git log master..HEAD --format='%h %s'
git log master..HEAD --format=%B | grep -ci 'co-authored-by'
```
Expected: valid; 100.0% coverage; no PHPStan errors; Pint PASS; release tests ok; Conventional Commit subjects only; the last command prints `0`.

- [ ] **Step 2: Matrix evidence**: include the Task 14 table (8 lines).

- [ ] **Step 3: Use superpowers:requesting-code-review** on `master..HEAD`; apply findings with superpowers:receiving-code-review, test-first for any bug.

- [ ] **Step 4: Use superpowers:finishing-a-development-branch.** Do not push or open a PR without the maintainer's explicit go-ahead.

- [ ] **Step 5: Final report**: changes per phase; breaking changes (points to UPGRADE.md); `@codeCoverageIgnore` list (expected: none); mutation score; PHPStan level; publiccode TODO fields; open questions:
  1. Real AAC userinfo claim names (`enti-*`, `fiscalCode` with or without `TINIT-` prefix).
  2. Security email `support@offlineagency.it` confirmation.
  3. Packagist submission and webhook.
  4. `CODECOV_TOKEN` secret and repository labels (`skip-release`).
  5. Whether to keep `docs/superpowers/` in the repository (it is export-ignored).
  6. CHANGELOG follow-up PR automation was not added (release notes are generated instead).
