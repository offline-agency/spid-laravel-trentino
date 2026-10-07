# Installation

This guide takes a Laravel application from zero to a working SPID login through AAC Trentino.

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.4 or 8.5 |
| Laravel | 12 (12.69 or later) or 13 (13.30 or later) |
| PHP extensions | `curl`, `json`, `openssl` (required by the OIDC client), plus your session and database drivers |
| AAC Trentino | An OIDC client registered on AAC (test or production) |

## 1. Install the package

```bash
composer require offline-agency/spid-laravel-trentino
```

Package discovery registers:

- the service provider `OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider`;
- the facade alias `SpidTrentino` (`OfflineAgency\SpidLaravelTrentino\SpidTrentinoFacade`);
- the middleware aliases `spid.valid` and `spid.refresh` (see [middleware](middleware.md));
- the routes `spid.login`, `spid.callback` and `spid.logout` (see [configuration](configuration.md#routes)).

## 2. Publish the configuration

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-config
```

This copies the package configuration to `config/spid-laravel-trentino.php`. Every key is described in [configuration](configuration.md).

## 3. Publish and run the migration

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-migrations
php artisan migrate
```

The migration adds the SPID columns to `users` and makes `email` and `password` nullable. Update your `User` model as described in [user model](user-model.md).

## 4. Publish the views (optional)

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-views
```

Views are copied to `resources/views/vendor/spid-laravel-trentino`. Publish them only if you want to change the login button markup.

## 5. Set the environment variables

```dotenv
SPID_TRENTINO_CLIENT_ID=your-client-id
SPID_TRENTINO_CLIENT_SECRET=your-client-secret
SPID_TRENTINO_REDIRECT_URI=https://your-app.test/spid/callback
SPID_TRENTINO_PROVIDER_URL=https://aac-test.cloud-test.tndigit.it
SPID_TRENTINO_SCOPES="openid profile.codicefiscale.me email offline_access"
SPID_TRENTINO_REDIRECT_TO=
```

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `SPID_TRENTINO_CLIENT_ID` | Yes | none | OIDC client id issued by AAC. When the variable is not set, resolving the OIDC client throws an `InvalidArgumentException` (the value must be a string). |
| `SPID_TRENTINO_CLIENT_SECRET` | No | none | Client secret. Leave empty for a public client (PKCE only). |
| `SPID_TRENTINO_REDIRECT_URI` | No | absolute URL of `routes.callback` | Must be identical to the redirect URI registered on AAC. |
| `SPID_TRENTINO_PROVIDER_URL` | No | `https://aac-test.cloud-test.tndigit.it` | AAC base URL. The default is the **test** environment. |
| `SPID_TRENTINO_SCOPES` | No | `openid profile.codicefiscale.me email offline_access` | Space separated scopes. |
| `SPID_TRENTINO_REDIRECT_TO` | No | none | Path used after login when there is no intended URL. |

After changing `.env` in an environment with cached configuration, run `php artisan config:clear` (or `config:cache` again).

## 6. Register the client on AAC

On the AAC console, for your OIDC client:

- **Redirect URI**: exactly the value of `SPID_TRENTINO_REDIRECT_URI`, or the absolute URL of the callback route (`https://your-app.test/spid/callback`) when the variable is empty. Scheme, host, port and path must match.
- **Scopes**: `openid`, `profile.codicefiscale.me` (fiscal code), `email`, and `offline_access` if you want refresh tokens (used by `spid.refresh`).
- **Grant type**: authorization code. The package uses PKCE with `S256` when the AAC discovery document lists it in `code_challenge_methods_supported`.
- **Environment**: use the test AAC URL for staging and the production URL in production (`SPID_TRENTINO_PROVIDER_URL`).

## 7. Protect your routes

The aliases are registered automatically. Put `spid.refresh` before `spid.valid`:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])->group(function () {
    Route::get('/area-riservata', fn () => view('private.dashboard'));
});
```

Add the login button to a view:

```blade
<x-spid-laravel-trentino::login-button />
```

### Registering the middleware manually (Laravel 12 and 13)

Only needed if you disabled package discovery for this package. Laravel 11 and later have no `app/Http/Kernel.php`; register the aliases in `bootstrap/app.php`:

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

In that case also register the provider and the facade yourself (`bootstrap/providers.php` and `config/app.php` aliases).

## Next steps

- [Configuration reference](configuration.md)
- [User model requirements](user-model.md)
- [Authentication flow](authentication-flow.md)
- [Troubleshooting](troubleshooting.md)
- Upgrading from 1.x: [UPGRADE.md](../UPGRADE.md)
