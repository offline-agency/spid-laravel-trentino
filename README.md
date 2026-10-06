# SPID Laravel Trentino

[![Latest Version on Packagist](https://img.shields.io/packagist/v/offline-agency/spid-laravel-trentino.svg?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Total Downloads](https://img.shields.io/packagist/dt/offline-agency/spid-laravel-trentino.svg?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![PHP Version](https://img.shields.io/packagist/dependency-v/offline-agency/spid-laravel-trentino/php?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/offline-agency/spid-laravel-trentino/illuminate%2Fsupport?label=laravel&style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Tests](https://img.shields.io/github/actions/workflow/status/offline-agency/spid-laravel-trentino/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/offline-agency/spid-laravel-trentino/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/codecov/c/github/offline-agency/spid-laravel-trentino?style=flat-square)](https://codecov.io/gh/offline-agency/spid-laravel-trentino)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat-square)](phpstan.neon)
[![License](https://img.shields.io/packagist/l/offline-agency/spid-laravel-trentino.svg?style=flat-square)](LICENSE.md)

SPID (Sistema Pubblico di Identità Digitale) login for Laravel applications through **AAC Trentino**.
The package implements the OpenID Connect authorization code flow with PKCE, creates or updates the local user by fiscal code, and keeps the session and tokens fresh with two middleware.

## Funding and reuse

This package was developed with funding from the [Consorzio dei Comuni Trentini](https://www.comunitrentini.it/) and is released as open source software for reuse by public administrations and other parties, in line with art. 69 of the Italian CAD (Codice dell'Amministrazione Digitale).

## Requirements

| Requirement | Supported versions |
|-------------|--------------------|
| PHP         | 8.4, 8.5           |
| Laravel     | 12 (12.69+), 13 (13.30+) |

Every combination (PHP 8.4 and 8.5, Laravel 12 and 13, lowest and latest dependencies) runs in CI.

## Installation

```bash
composer require offline-agency/spid-laravel-trentino
```

The service provider, the `SpidTrentino` facade alias and the middleware aliases are registered automatically.

Publish the configuration and the migration, then migrate:

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-config
php artisan vendor:publish --tag=spid-laravel-trentino-migrations
php artisan migrate
```

Optionally publish the Blade login button to customize it:

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-views
```

Add the AAC credentials to `.env`:

```dotenv
SPID_TRENTINO_CLIENT_ID=your-client-id
SPID_TRENTINO_CLIENT_SECRET=your-client-secret
SPID_TRENTINO_REDIRECT_URI=https://your-app.test/spid/callback
SPID_TRENTINO_PROVIDER_URL=https://aac-test.cloud-test.tndigit.it
SPID_TRENTINO_SCOPES="openid profile.codicefiscale.me email offline_access"
SPID_TRENTINO_REDIRECT_TO=
```

### Registering the client on AAC

- The redirect URI registered on AAC must match `SPID_TRENTINO_REDIRECT_URI` exactly. When it is empty, the package uses the absolute URL of the callback route (`/spid/callback` by default).
- The client needs the `openid`, `profile.codicefiscale.me`, `email` and `offline_access` scopes (`offline_access` provides the refresh token).
- `SPID_TRENTINO_PROVIDER_URL` defaults to the AAC **test** environment. Set the production URL in production.

## Configuration

All keys live in `config/spid-laravel-trentino.php`.

| Key | Default | Description |
|-----|---------|-------------|
| `client_id` | `env('SPID_TRENTINO_CLIENT_ID')` | OIDC client id issued by AAC (required). |
| `client_secret` | `env('SPID_TRENTINO_CLIENT_SECRET')` | OIDC client secret. Leave empty for a public client (PKCE only). |
| `redirect_uri` | `env('SPID_TRENTINO_REDIRECT_URI')` | Redirect URI registered on AAC. `null` uses the URL of `routes.callback`. |
| `provider_url` | `https://aac-test.cloud-test.tndigit.it` | AAC base URL. The discovery document is read from `{provider_url}/.well-known/openid-configuration`. |
| `scopes` | `openid profile.codicefiscale.me email offline_access` | Space separated scopes. `openid` is always sent. |
| `cache_ttl` | `3600` | Seconds the discovery document and the JWKS are cached. `0` disables caching. |
| `register_routes` | `true` | Set to `false` to register your own routes (keep the names `spid.login`, `spid.callback`, `spid.logout`). |
| `routes.login` | `/spid/login` | Path of the login route. |
| `routes.callback` | `/spid/callback` | Path of the callback route (must match the redirect URI). |
| `routes.logout` | `/spid/logout` | Path of the logout route. |
| `auth_controller` | `SpidAuthController::class` | Controller handling login, callback and logout. |
| `redirect_to` | `env('SPID_TRENTINO_REDIRECT_TO')` | Path used after login when there is no intended URL. `null` sends admins (users with `hasRole('admin')`) to `/admin/dashboard` and everybody else to `/`. |
| `logout_redirect_to` | `/` | Path used after logout. |
| `error_redirect_to` | `/` | Path used when the SPID login fails. |

## Routes

| Method | Path | Name | Purpose |
|--------|------|------|---------|
| GET | `/spid/login` | `spid.login` | Redirects the user to AAC |
| GET | `/spid/callback` | `spid.callback` | Completes the login and authenticates the local user |
| POST | `/spid/logout` | `spid.logout` | Logs out and ends the session |

All three routes use the `web` middleware group. The logout route lives under `/spid` so it does not collide with the `/logout` route of Breeze, Jetstream or Fortify.

## Middleware

Two aliases are registered automatically:

| Alias | Class | Behavior |
|-------|-------|----------|
| `spid.refresh` | `RefreshSpidTokenIfNeeded` | Refreshes the access token when it expires within 60 seconds. If the refresh fails, the tokens are forgotten. |
| `spid.valid` | `EnsureValidSpidToken` | Ends the session when the SPID user or the access token is missing, or the token has expired. HTML requests are redirected to `spid.login`; JSON requests get `419` with `{"message": "SPID session missing or expired."}`. |

Put `spid.refresh` **before** `spid.valid`, so a token about to expire is refreshed before it is checked:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->group(function () {
    Route::get('/area-riservata', fn () => view('private.dashboard'));
});
```

If you disabled package discovery, register the aliases yourself in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'spid.valid' => EnsureValidSpidToken::class,
        'spid.refresh' => RefreshSpidTokenIfNeeded::class,
    ]);
})
```

## User model

The published migration adds these columns to `users`: `fiscal_code` (unique), `surname`, `preferred_username`, `locale`, `zoneinfo` and `spid_profile` (JSON). It also makes `email` and `password` nullable, because SPID users have no local password and may have no email.

Add the attributes to `$fillable` and cast `spid_profile` to an array:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
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
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'spid_profile' => 'array',
        ];
    }
}
```

On every login the user is found by `fiscal_code` (or created), the profile columns are updated and the full SPID payload is stored in `spid_profile`. The email from SPID is written only when no other user already has it: the package never links or takes over an existing account by email.

### Customizing the login

Extend the controller and point `auth_controller` at it:

```php
namespace App\Http\Controllers\Auth;

use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController as BaseController;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

class SpidAuthController extends BaseController
{
    protected function authenticateFromSpid(SpidTrentinoUser $spidUser): void
    {
        parent::authenticateFromSpid($spidUser);

        $user = auth()->user();

        if (! $user->hasRole('citizen')) {
            $user->assignRole('citizen');
        }
    }

    protected function redirectTo(): string
    {
        return '/area-riservata';
    }
}
```

```php
// config/spid-laravel-trentino.php
'auth_controller' => \App\Http\Controllers\Auth\SpidAuthController::class,
```

(`hasRole()` and `assignRole()` come from `spatie/laravel-permission`; use whatever your application provides.)

## Login button

```blade
<x-spid-laravel-trentino::login-button />

<x-spid-laravel-trentino::login-button label="Accedi con SPID" class="mt-4" />
```

The button links to `route('spid.login')`, and extra classes are merged with the default ones.

## Session keys

Use the constants of `OfflineAgency\SpidLaravelTrentino\SessionKeys` instead of literal keys:

| Constant | Key | Content |
|----------|-----|---------|
| `SessionKeys::USER` | `spid_trentino_user` | SPID user payload (`SpidTrentinoUser::toArray()`) |
| `SessionKeys::ACCESS_TOKEN` | `spid_trentino_access_token` | Access token |
| `SessionKeys::REFRESH_TOKEN` | `spid_trentino_refresh_token` | Refresh token, when AAC issues one |
| `SessionKeys::ACCESS_TOKEN_EXPIRES_AT` | `spid_trentino_access_token_expires_at` | Access-token expiry (ISO-8601 string) |
| `SessionKeys::ERROR` | `spid_trentino_error` | Flash message set when the login fails |
| `SessionKeys::OIDC_PREFIX` | `spid_trentino_oidc_` | Prefix of the state, nonce and PKCE verifier kept during the login round trip |

## Events

| Event | When |
|-------|------|
| `SpidTrentinoLoggedIn` | After a successful AAC callback, before the local user is authenticated |
| `SpidTrentinoLoggedOut` | After `SpidTrentino::logout()` ended a SPID session |

Both expose the `SpidTrentinoUser` as `$event->user` (or `$event->getUser()`):

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;

Event::listen(function (SpidTrentinoLoggedIn $event) {
    Log::info('SPID login', LogRedactor::user($event->user));
});
```

## Tokens, verification and errors

- **ID token verification** is done by `jumbojett/openid-connect-php` inside the code flow: the signature is checked with the keys published at the `jwks_uri` of the discovery document, and so are the `iss`, `aud`, `sub`, `nonce`, `exp`, `nbf` and `at_hash` claims. The package adds stricter checks: tokens without `exp`, or whose `aud` does not contain the client id, are rejected.
- **Caching**: the discovery document and the JWKS are cached for `cache_ttl` seconds. If AAC rotates its signing key, the cached JWKS is dropped and fetched again once.
- **Refresh**: `spid.refresh` refreshes the access token within 60 seconds of expiry. If AAC refuses the refresh or cannot be reached, the tokens are forgotten and `spid.valid` sends the user back to the login.
- **Failures**: when the login fails (AAC unreachable, user cancelled, invalid ID token, no fiscal code returned), the user is redirected to `error_redirect_to` with a message flashed under `SessionKeys::ERROR`, and the exception is logged:

```blade
@if (session()->has(\OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR))
    <div class="alert alert-danger">
        {{ session(\OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR) }}
    </div>
@endif
```

- **Octane and queues**: the OIDC state lives in the Laravel session (never in the native PHP session), redirects are returned as responses instead of calling `exit`, and `SpidTrentino` is bound per request.

## Logging and privacy

The package never logs tokens, the client secret or personal data. Users are identified in log context by `LogRedactor`, which stores a keyed hash (HMAC-SHA256 with `app.key`, truncated) of the subject and of the fiscal code.

## Testing

```bash
composer test            # run the suite
composer test-coverage   # run with coverage, fails below 100%
composer analyse         # PHPStan (Larastan) at level max
composer format          # fix code style with Pint
composer mutate          # mutation testing
```

### Faking SPID in your application tests

Bind `MockOpenIDConnectClient` to skip AAC entirely. It returns fixed tokens and an AAC-shaped userinfo payload (fiscal code `TINIT-RSSMRA80A01H501U`) that you can override:

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

## Release process

Releases are automatic. When a pull request is merged into `master`, the `Release` workflow creates an annotated tag and a GitHub Release with generated notes. The version is computed from the latest `vX.Y.Z` tag and the pull request title, which must follow [Conventional Commits](https://www.conventionalcommits.org/) (a CI check enforces it):

| Pull request | Next version |
|--------------|--------------|
| `feat!: ...`, `fix(scope)!: ...`, or a `BREAKING CHANGE:` line in the description | major |
| `feat: ...` | minor |
| anything else (`fix:`, `docs:`, `chore:` ...) | patch |

Label a pull request `skip-release` to merge it without releasing. The first release is `v2.0.0`.

Packagist picks up new tags through its GitHub webhook. To verify it is active, open the package page on Packagist (it shows when the package was last updated and no "not auto-updated" warning), and check that the repository's **Settings, Webhooks** page lists `https://packagist.org/api/github` with a green check on the latest delivery.

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the changes from 1.x to 2.0.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) to report vulnerabilities privately.

## Credits

- [Giacomo Fabbian](https://github.com/Giacomo92)
- [All Contributors](https://github.com/offline-agency/spid-laravel-trentino/graphs/contributors)

## About us

Offline Agency is a web design agency based in Padua, Italy. You'll find an overview of our projects [on our website](https://offlineagency.it/).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
