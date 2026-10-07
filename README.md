# SPID Laravel Trentino

[![Latest Version on Packagist](https://img.shields.io/packagist/v/offline-agency/spid-laravel-trentino.svg?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Total Downloads](https://img.shields.io/packagist/dt/offline-agency/spid-laravel-trentino.svg?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![PHP Version](https://img.shields.io/packagist/dependency-v/offline-agency/spid-laravel-trentino/php?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/offline-agency/spid-laravel-trentino/illuminate%2Fsupport?label=laravel&style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Tests](https://img.shields.io/github/actions/workflow/status/offline-agency/spid-laravel-trentino/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/offline-agency/spid-laravel-trentino/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/codecov/c/github/offline-agency/spid-laravel-trentino/master?style=flat-square)](https://codecov.io/gh/offline-agency/spid-laravel-trentino)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat-square)](phpstan.neon)
[![License](https://img.shields.io/github/license/offline-agency/spid-laravel-trentino?style=flat-square)](https://github.com/offline-agency/spid-laravel-trentino/blob/master/LICENSE.md)

SPID (Sistema Pubblico di Identità Digitale) login for Laravel applications through **AAC Trentino**.
The package runs the OpenID Connect authorization code flow with PKCE, creates or updates the local user by fiscal code, and keeps the session and tokens fresh with two middleware.

## Funding and reuse

This package was developed with funding from the [Consorzio dei Comuni Trentini](https://www.comunitrentini.it/) and is released as open source software for reuse by public administrations and other parties, in line with art. 69 of the Italian CAD (Codice dell'Amministrazione Digitale).

## Requirements

| Requirement | Supported versions |
|-------------|--------------------|
| PHP | 8.4, 8.5 |
| Laravel | 12 (12.69+), 13 (13.30+) |
| AAC Trentino | An OIDC client with the redirect URI of your callback route and the scopes `openid profile.codicefiscale.me email offline_access` |

Every combination (PHP 8.4 and 8.5, Laravel 12 and 13, lowest and latest dependencies) runs in CI.

## Quick start

**1. Install**

```bash
composer require offline-agency/spid-laravel-trentino
```

**2. Publish the configuration and the migration, then migrate**

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-config
php artisan vendor:publish --tag=spid-laravel-trentino-migrations
php artisan migrate
```

**3. Configure `.env`**

```dotenv
SPID_TRENTINO_CLIENT_ID=your-client-id
SPID_TRENTINO_CLIENT_SECRET=your-client-secret
SPID_TRENTINO_REDIRECT_URI=https://your-app.test/spid/callback
SPID_TRENTINO_PROVIDER_URL=https://aac-test.cloud-test.tndigit.it
```

Register the same redirect URI on AAC. The provider URL above is the AAC **test** environment.

**4. Prepare the user model**

```php
protected $fillable = [
    'name', 'email', 'password',
    'fiscal_code', 'surname', 'preferred_username', 'locale', 'zoneinfo',
];

protected function casts(): array
{
    return [
        'password' => 'hashed',
        'spid_profile' => 'array',
    ];
}
```

**5. Protect your routes and add the login button**

The middleware aliases are registered by the package; put `spid.refresh` before `spid.valid`. If SPID is your only login, send guests to it in `bootstrap/app.php` (Laravel's `auth` middleware otherwise redirects to a route named `login`):

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(fn () => route('spid.login'));
})
```

Then add the button to a view:

```blade
<x-spid-laravel-trentino::login-button />
```

## Minimal example

`routes/web.php`:

```php
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->group(function () {
    Route::get('/area-riservata', fn () => 'Benvenuto, '.auth()->user()->name);
});
```

`resources/views/welcome.blade.php`:

```blade
@if (session()->has(\OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR))
    <p>{{ session(\OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR) }}</p>
@endif

@auth
    <form method="POST" action="{{ route('spid.logout') }}">
        @csrf
        <button type="submit">Esci</button>
    </form>
@else
    <x-spid-laravel-trentino::login-button />
@endauth
```

Opening `/area-riservata` as a guest sends the user to `spid.login` (through `auth` and `redirectGuestsTo()` from step 5). Clicking the button starts the SPID login at `/spid/login`; after AAC the user returns to `/spid/callback`, is created or updated by fiscal code, logged in, and redirected to the page they asked for.

## Documentation

For applications using the package:

- [Installation](docs/installation.md): requirements, publishing, environment variables, AAC registration, middleware
- [Configuration](docs/configuration.md): every configuration key and the default routes
- [User model](docs/user-model.md): columns, `$fillable`, casts, how users are matched
- [Authentication flow](docs/authentication-flow.md): login, callback and logout sequences
- [Session and tokens](docs/session-and-tokens.md): session keys, expiry, refresh
- [Middleware](docs/middleware.md): `spid.refresh` and `spid.valid`
- [Events](docs/events.md): `SpidTrentinoLoggedIn` and `SpidTrentinoLoggedOut`
- [User DTO](docs/user-dto.md): `SpidTrentinoUser` and the AAC claims
- [Extending](docs/extending.md): custom controller, own routes, login button, service API
- [Troubleshooting](docs/troubleshooting.md): log messages, causes and fixes
- [Security](docs/security.md): what is validated, logging, production checklist
- [Transaction log](docs/transaction-log.md): SPID/CIE OIDC retention policy (24 months), pruning, integrity checks

For maintainers:

- [Architecture](docs/architecture.md): class map and container bindings
- [Development](docs/development.md): tests, quality gates, CI, releases
- [Known issues](docs/known-issues.md): open issues in 2.0 and 1.x problems fixed in 2.0

Also: [UPGRADE.md](UPGRADE.md) (1.x to 2.0), [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report vulnerabilities privately, see [SECURITY.md](SECURITY.md) (contact: support@offlineagency.it).

## Credits

- [Giacomo Fabbian](https://github.com/Giacomo92)
- [All Contributors](https://github.com/offline-agency/spid-laravel-trentino/graphs/contributors)

## About us

Offline Agency is a web design agency based in Padua, Italy. You'll find an overview of our projects [on our website](https://offlineagency.it/).

## License

The MIT License (MIT). Please see [License File](https://github.com/offline-agency/spid-laravel-trentino/blob/master/LICENSE.md) for more information.
