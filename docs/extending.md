# Extending

## Custom controller

The routes use the controller in `auth_controller` (default `OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController`). Extend it to change how users are authenticated or where they go after login:

```php
<?php

namespace App\Http\Controllers\Auth;

use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController as BaseController;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

class SpidAuthController extends BaseController
{
    protected function authenticateFromSpid(SpidTrentinoUser $spidUser): void
    {
        parent::authenticateFromSpid($spidUser);

        // The local user is now logged in.
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

Then point the configuration at it:

```php
// config/spid-laravel-trentino.php
'auth_controller' => \App\Http\Controllers\Auth\SpidAuthController::class,
```

Run `php artisan config:clear` (or `route:clear` if you cache routes) after the change.

### Methods you can override

| Method (from `SpidAuthenticatesUsers`) | Default behavior |
|----------------------------------------|------------------|
| `authenticateFromSpid(SpidTrentinoUser $spidUser): void` | Find or create the user by fiscal code, sync profile, log in (see [user model](user-model.md)) |
| `redirectTo(): string` | `redirect_to`, then `/admin/dashboard` for admins, then `/` |
| `spidLoginFailed(OpenIDConnectClientException $exception): RedirectResponse` | Log `[SPID] Authentication failed`, redirect to `error_redirect_to` with the flash message under `SessionKeys::ERROR`. For an `AuthenticationRejected` exception (`required_acr`, `allowed_issuer_sources`) it logs `[SPID] Login rejected` at warning level and flashes `$exception->userMessage()`; overrides should keep that branch (or call `parent::spidLoginFailed()`) |

The controller actions `login()`, `callback()` and `logout()` are public and can be overridden too.

### Rejecting users

To allow only some users, check the SPID identity before calling the parent and fail the login the same way the package does:

```php
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use Illuminate\Http\RedirectResponse;

public function callback(SpidTrentino $spid): RedirectResponse
{
    try {
        $spidUser = $spid->handleCallback();
    } catch (OpenIDConnectClientException $exception) {
        return $this->spidLoginFailed($exception);
    }

    if (! \App\Models\AllowedCitizen::where('fiscal_code', $spidUser->getFiscalNumber())->exists()) {
        $spid->logout();

        return redirect('/not-allowed');
    }

    $this->authenticateFromSpid($spidUser);

    return redirect()->intended($this->redirectTo());
}
```

(`AllowedCitizen` stands for your own model.)

## Using roles (spatie/laravel-permission)

`redirectTo()` already sends users with `hasRole('admin')` to `/admin/dashboard`, so the default works with `spatie/laravel-permission` once the `HasRoles` trait is on your user model. Assign roles in `authenticateFromSpid()` as shown above.

## Using the trait in your own controller

If you do not want to extend the package controller, use the trait and provide the three actions the routes call:

```php
<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers;

class SpidController extends Controller
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

        return redirect()->intended($this->redirectTo());
    }

    public function logout(SpidTrentino $spid): RedirectResponse
    {
        $spid->logout();

        return redirect('/');
    }
}
```

Catch `OpenIDConnectClientException` in `login()` and `callback()`, otherwise an AAC outage shows a 500 page. (The snippet in UPGRADE.md omits it, see [KI-08](known-issues.md#ki-08-upgrade-guide-snippet-for-custom-controllers-has-no-error-handling).)

## Registering your own routes

Set `register_routes` to `false` and define the routes yourself, keeping the names (`spid.valid` redirects to `spid.login`):

```php
use App\Http\Controllers\Auth\SpidController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('accesso')->group(function () {
    Route::get('/spid', [SpidController::class, 'login'])->name('spid.login');
    Route::get('/spid/ritorno', [SpidController::class, 'callback'])->name('spid.callback');
    Route::post('/spid/esci', [SpidController::class, 'logout'])->name('spid.logout');
});
```

Remember to register the new callback URL on AAC (and set `SPID_TRENTINO_REDIRECT_URI`, or leave it empty and update `routes.callback` so the default matches).

## Calling the service directly

`OfflineAgency\SpidLaravelTrentino\SpidTrentino` is bound per request in the container; inject it or use the `SpidTrentino` facade:

| Method | Description |
|--------|-------------|
| `redirectToLogin(): RedirectResponse` | Redirect to the AAC authorization endpoint |
| `handleCallback(): SpidTrentinoUser` | Complete the code flow and store tokens and user in the session |
| `refreshAccessToken(): void` | Refresh the access token with the stored refresh token |
| `logout(): void` | Log out, end the session, dispatch `SpidTrentinoLoggedOut` |
| `getUserInfo(): ?object` | Call AAC's userinfo endpoint with the access token in the session |

```php
use SpidTrentino;

$claims = SpidTrentino::getUserInfo();
```

All methods except `logout()` can throw `Jumbojett\OpenIDConnectClientException`.

## Replacing the OIDC client

The service receives its client from the container (`OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient`). Bind your own subclass to change its behavior, for example a proxy or a custom timeout:

```php
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

$this->app->extend(LaravelOpenIDConnectClient::class, function (LaravelOpenIDConnectClient $client) {
    $client->setTimeout(10);

    return $client;
});
```

For tests use `MockOpenIDConnectClient`, see [development](development.md#mockopenidconnectclient).

## Login button

```blade
<x-spid-laravel-trentino::login-button />

<x-spid-laravel-trentino::login-button label="Accedi con SPID" class="mt-4" />
```

The anonymous Blade component renders a link to `route('spid.login')` with the default label "Entra con SPID" and the classes `btn btn-light-primary align-self-center w-100`; your `class` is appended. To change the markup, publish the views (`--tag=spid-laravel-trentino-views`) and edit `resources/views/vendor/spid-laravel-trentino/components/login-button.blade.php`.

The Vue component of 1.x (`AacLoginButton.vue`) was removed in 2.0.
