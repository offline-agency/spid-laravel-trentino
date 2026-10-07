# Upgrade Guide

## Upgrading from 2.x to 3.0

### Fiscal codes are normalized (required)

The fiscal code used to find and store local users is now normalized: trimmed, uppercase and without the `TINIT-` prefix (`TINIT-RSSMRA80A01H501U` becomes `RSSMRA80A01H501U`). `SpidTrentinoUser::getFiscalNumber()` returns that form; the raw claim is still available in `getEntiCodiceFiscale()` and in `spid_profile`.

Users stored in the old form are no longer found at login: without the steps below, each of them would get a second account on the next login. Before deploying 3.0:

1. Check what would change, on a copy of production data if possible:

   ```bash
   php artisan spid:normalize-fiscal-codes --dry-run
   ```

2. If the command reports conflicts (`Normalizing would give one fiscal code to several users`), the listed users already are duplicates of the same person (for example one created with `TINIT-` and one without). Merge them, or fix their `fiscal_code`, and run the dry run again. The command prints user ids and a keyed hash, never the fiscal codes.

3. Normalize, in the same deployment step that ships 3.0 (put the application in maintenance mode so no login runs in between):

   ```bash
   php artisan down
   php artisan spid:normalize-fiscal-codes
   php artisan up
   ```

   The command changes nothing at all unless it can normalize every user without creating duplicates.

Also check:

- Code that compares `fiscal_code` or `getFiscalNumber()` with a `TINIT-` value, and other systems that receive the stored fiscal code.
- Tests that assert `MockOpenIDConnectClient::FISCAL_CODE` as the stored value: use `MockOpenIDConnectClient::NORMALIZED_FISCAL_CODE`.
- Log correlation: the `fiscal_code` hash written by `LogRedactor` is now computed on the normalized value, so it differs from the hash in logs written by 2.x.

## Upgrading from 1.x to 2.0

2.0 fixes several bugs that made 1.x unusable in practice (the `spid.valid` middleware logged every user out, `redirect_to` was never applied, the facade did not resolve). Most applications only need the steps marked **required**.

### Requirements (required)

PHP 8.4+ and Laravel 12.69+ or 13.30+. Laravel 10 and 11 are no longer supported.

### Configuration file (required)

The configuration file is `config/spid-laravel-trentino.php`, published with:

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-config
```

If you published `config/spid.php` in 1.x, move your values to the new file and delete the old one. New keys: `cache_ttl`, `register_routes`, `logout_redirect_to`, `error_redirect_to`.

`redirect_to` is now actually applied. Its default is `null` (1.x shipped `/dashboard` but never read it), which sends admins to `/admin/dashboard` and everybody else to `/`. Set `SPID_TRENTINO_REDIRECT_TO` if you want a fixed path.

### Migration (required)

Publish and run the migration that adds the SPID columns and makes `email` and `password` nullable:

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-migrations
php artisan migrate
```

Add the columns to the user model's `$fillable` and cast `spid_profile` to `array` (see the README).

### Logout route (required if you link to it)

```diff
- POST /logout       (name: spid.logout)
+ POST /spid/logout  (name: spid.logout)
```

Use `route('spid.logout')` instead of a literal path.

### Middleware (required)

The aliases are registered by the package: remove `spid.valid` and `spid.refresh` from `app/Http/Kernel.php` or `bootstrap/app.php`. Put `spid.refresh` **before** `spid.valid`:

```diff
- Route::middleware(['web', 'auth', 'spid.valid', 'spid.refresh'])
+ Route::middleware(['web', 'auth', 'spid.refresh', 'spid.valid'])
```

### Session keys

All keys are constants of `OfflineAgency\SpidLaravelTrentino\SessionKeys`. Users logged in under 1.x must log in again.

| 1.x key | 2.0 key |
|---------|---------|
| `spid_trentino_user` | `spid_trentino_user` (`SessionKeys::USER`, unchanged) |
| `access_token` | `spid_trentino_access_token` (`SessionKeys::ACCESS_TOKEN`) |
| `refresh_token` | `spid_trentino_refresh_token` (`SessionKeys::REFRESH_TOKEN`) |
| `access_token_expires_at` (Carbon) | `spid_trentino_access_token_expires_at` (ISO-8601 string, `SessionKeys::ACCESS_TOKEN_EXPIRES_AT`) |

### Custom controllers

The login route is now handled by `SpidAuthController::login()`. Controllers that extend `SpidAuthController` inherit it; controllers that only use the `SpidAuthenticatesUsers` trait must add:

```php
public function login(SpidTrentino $spid): RedirectResponse
{
    return $spid->redirectToLogin();
}
```

`SpidAuthenticatesUsers::spidLogout()` was removed because it skipped the `SpidTrentinoLoggedOut` event. Call `SpidTrentino::logout()` (or the facade) instead.

Failed logins no longer throw: the controller redirects to `error_redirect_to` with a flash message under `SessionKeys::ERROR`. A login whose userinfo has no fiscal code now fails instead of matching a user with an empty fiscal code.

### Service and facade

- The `spid-laravel-trentino.auth` container binding was removed. Resolve `SpidTrentino::class` or use the `SpidTrentino` facade.
- `SpidTrentino` is bound per request and its constructor takes a `LaravelOpenIDConnectClient` (resolved from the container).
- `SpidTrentino::redirectToLogin()` returns a `RedirectResponse` (it used to return `bool` and call `exit`).
- `SpidTrentino::handleCallback()` returns the `SpidTrentinoUser`.

### Events

`SpidTrentinoLoggedIn` and `SpidTrentinoLoggedOut` expose a readonly `$user` and `getUser()`. `setUser()`, `Dispatchable` and `SerializesModels` were removed; dispatch them with `event(new SpidTrentinoLoggedIn($user))` if you ever need to.

### DTO

`SpidTrentinoUser` maps the `email` claim (`getEmail()`, included in `toArray()`). Claims of an unexpected type become `''` or `[]` instead of throwing a `TypeError`.

### Testing

`MockOpenIDConnectClient` now extends `LaravelOpenIDConnectClient`, uses the AAC `enti-codicefiscale` claim and is bound like this:

```php
$this->app->instance(LaravelOpenIDConnectClient::class, new MockOpenIDConnectClient);
```

### Dependencies

- `jumbojett/openid-connect-php` moved to 1.x.
- `firebase/php-jwt` is no longer installed by this package. Require it yourself if your application used it.
- `laravel/framework` is no longer a direct requirement (the package depends on `illuminate/*` components).

### Views

The Vue component `AacLoginButton.vue` was removed. The Blade button links to `route('spid.login')` and its default label is "Entra con SPID".
