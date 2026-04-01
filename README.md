# SPID Laravel Trentino
[![Latest Stable Version](https://poser.pugx.org/offline-agency/spid-laravel-trentino/v/stable)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Total Downloads](https://img.shields.io/packagist/dt/offline-agency/spid-laravel-trentino.svg?style=flat-square)](https://packagist.org/packages/offline-agency/spid-laravel-trentino)
[![Build Status](https://github.com/offline-agency/spid-laravel-trentino/actions/workflows/test.yml/badge.svg)](https://github.com/offline-agency/spid-laravel-trentino/actions/workflows/test.yml)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
This package provides integration with AAC Trentino via OpenID Connect, allowing users to authenticate using SPID (Sistema Pubblico di Identità Digitale) with full Laravel compatibility.

---

## Features

* SPID login and callback via AAC Trentino (PKCE)
* Stores user data and token info in session
* Emits `SpidTrentinoLoggedIn` and `SpidTrentinoLoggedOut` events
* Middleware for:

  * Ensuring valid SPID session
  * Auto-refreshing tokens via `refresh_token`
* Extensible controller logic via config
* Compatible with `spatie/laravel-permission` (optional)
* `access_token_expires_at` support

---

## Installation

```bash
composer require offline-agency/spid-laravel-trentino
```

---

## Configuration

```bash
php artisan vendor:publish --tag=spid-config
```

### `config/spid.php`

```php
return [

    // OIDC Credentials
    'client_id'     => env('SPID_TRENTINO_CLIENT_ID'),
    'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),
    'redirect_uri'  => env('SPID_TRENTINO_REDIRECT_URI'),
    'provider_url'  => env('SPID_TRENTINO_PROVIDER_URL', 'https://aac-test.cloud-test.tndigit.it'),
    'scopes'        => env('SPID_TRENTINO_SCOPES', 'openid profile.codicefiscale.me email offline_access'),

    // Routes
    'routes' => [
        'login'    => '/spid/login',
        'callback' => '/spid/callback',
        'logout'   => '/logout',
    ],

    // Controller to handle SPID login/logout flow
    'auth_controller' => \OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController::class,

    // Where to redirect after login (null = dynamic logic via trait)
    'redirect_to' => '/dashboard',
];
```

---

## Routes

The package defines 3 default routes:

| Method | Path             | Purpose                         |
| ------ | ---------------- | ------------------------------- |
| GET    | `/spid/login`    | Redirects user to AAC login     |
| GET    | `/spid/callback` | Handles AAC callback            |
| POST   | `/logout`        | Logs out and clears the session |

You can override both paths and controller from the config.

---

## Middleware

Register in `app/Http/Kernel.php`:

```php
protected $routeMiddleware = [
    'spid.valid'   => \OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken::class,
    'spid.refresh' => \OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded::class,
];
```

Use in your routes:

```php
Route::middleware(['web', 'auth', 'spid.valid', 'spid.refresh'])->group(function () {
    Route::get('/area-riservata', fn () => view('private.dashboard'));
});
```

---

## Extending Behavior

You can override the controller to customize login behavior:

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
        if ($user->exists && ! $user->hasRole('default')) {
            $user->assignRole('default');
        }
    }
}
```

And update `config/spid.php`:

```php
'auth_controller' => \App\Http\Controllers\Auth\SpidAuthController::class,
```

---

## Session Values

| Key                       | Description                             |
| ------------------------- | --------------------------------------- |
| `spid_trentino_user`      | Array of decoded user info              |
| `access_token`            | Raw OIDC access token                   |
| `refresh_token`           | Token for refreshing (if available)     |
| `access_token_expires_at` | `Carbon` instance with expiry timestamp |

---

## Events

You may listen to:

* `SpidTrentinoLoggedIn(SpidTrentinoUser $user)`
* `SpidTrentinoLoggedOut(SpidTrentinoUser $user)`

Example:

```php
Event::listen(SpidTrentinoLoggedIn::class, function ($event) {
    // handle login
});
```

---

## Token Expiry

The package automatically stores `access_token_expires_at` if `expires_in` is available in the token response.
Use the provided middleware to refresh tokens or invalidate sessions gracefully.

---

## Transaction Log (SPID Compliance)

The Italian SPID/CIE OIDC regulations ([Retention Policy](https://docs.italia.it/italia/spid/spid-cie-oidc-docs/it/versione-corrente/log_management.html)) mandate that every Relying Party maintain an encrypted transaction log of all OIDC authentication messages for a **minimum of 24 months**.

This package includes a fully compliant transaction log subsystem.

### Setup

Publish and run the migration:

```bash
php artisan vendor:publish --tag=spid-migrations
php artisan migrate
```

### Configuration

The following keys are available in `config/spid-laravel-trentino.php`:

```php
'transaction_log' => [
    'enabled'          => env('SPID_TRANSACTION_LOG_ENABLED', true),
    'table'            => env('SPID_TRANSACTION_LOG_TABLE', 'spid_transaction_logs'),
    'retention_months' => env('SPID_TRANSACTION_LOG_RETENTION_MONTHS', 24),
],
```

Set `SPID_TRANSACTION_LOG_ENABLED=false` in your `.env` to disable logging (useful for local development or test environments).

### What gets logged

Every OIDC lifecycle event is recorded automatically:

| Event | Trigger |
|---|---|
| `authentication_request` | When the user is redirected to the IdP |
| `authentication_response` | When the IdP callback is received |
| `token_request` | Before token exchange |
| `token_response` | After token exchange |
| `userinfo_request` | Before userinfo fetch |
| `userinfo_response` | After userinfo fetch |
| `refresh_request` | Before access token refresh |
| `refresh_response` | After access token refresh |
| `logout` | On SPID logout |

### Security properties

- **Encryption at rest**: the `payload` column uses Laravel's `encrypted` cast (AES-256-CBC via `APP_KEY`).
- **Integrity / non-repudiation**: every record stores a `payload_hmac` (HMAC-SHA256 of the raw JSON payload before encryption). Use `verifyIntegrity()` to verify a record has not been tampered with:

```php
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

$record = SpidTransactionLog::find($id);

if (! $record->verifyIntegrity()) {
    // Record has been tampered with
}
```

- **No sensitive secrets logged**: `client_secret` is never included in any log payload.

### Pruning expired records

Records older than the retention threshold can be pruned with:

```bash
php artisan spid:prune-logs
php artisan spid:prune-logs --months=36   # custom retention (min 24)
php artisan spid:prune-logs --dry-run     # preview without deleting
```

Schedule daily pruning in your console kernel:

```php
$schedule->command('spid:prune-logs')->daily();
```

The command will refuse to prune below 24 months, clamping the value and emitting a warning if a shorter period is requested.

---

## Contributing
Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security
If you discover any security-related issues, please email support@offlineagency.com instead of using the issue tracker.

## Credits
- [Giacomo Fabbian](https://github.com/Giacomo92)

- [All Contributors](https://github.com/offline-agency/laravel-mongo-auto-sync/graphs/contributors)

## About us
Offline Agency is a web design agency based in Padua, Italy. You'll find an overview of our projects [on our website](https://offlineagency.it/#home).

## License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
