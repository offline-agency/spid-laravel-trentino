# User model

After a successful callback, `SpidAuthController` calls `authenticateFromSpid()` from the `OfflineAgency\SpidLaravelTrentino\Traits\SpidAuthenticatesUsers` trait. This page describes what that method needs from your application's user model.

## What `authenticateFromSpid()` does

1. Reads the model class from `auth.providers.users.model`. It must be an Eloquent model, otherwise a `LogicException` is thrown: `The user model [...] must be an Eloquent model.`
2. Finds the user with `firstOrNew(['fiscal_code' => ...])`, using the fiscal code from the SPID payload (`SpidTrentinoUser::getFiscalNumber()`).
3. Checks the model implements `Illuminate\Contracts\Auth\Authenticatable`, otherwise throws `The user model [...] must implement Authenticatable.`
4. Sets `fiscal_code` explicitly (it does not rely on `$fillable` for this column).
5. Calls `fill()` with `name`, `surname`, `preferred_username`, `locale` and `zoneinfo`.
6. Sets `email` from SPID only when it is not empty and **no other user** already has that email. The package never links or takes over an existing account by email.
7. Sets `spid_profile` to the full SPID payload (`SpidTrentinoUser::toArray()`).
8. Saves the model, calls `Auth::login()` and regenerates the session id.

The same user is updated on every login, so profile changes made on the SPID side are copied to your database.

## Columns

The package migration (`php artisan vendor:publish --tag=spid-laravel-trentino-migrations`) adds:

| Column | Type | Nullable | Source |
|--------|------|----------|--------|
| `fiscal_code` | string, unique | yes | `enti-codicefiscale.fiscalCode` |
| `surname` | string | yes | `family_name` |
| `preferred_username` | string | yes | `preferred_username` |
| `locale` | string | yes | `locale` |
| `zoneinfo` | string | yes | `zoneinfo` |
| `spid_profile` | json | yes | the whole payload, see [user DTO](user-dto.md) |

It also changes existing columns:

| Column | Change | Why |
|--------|--------|-----|
| `email` | made nullable | SPID may not return an email, and an email owned by another user is not copied |
| `password` | made nullable | SPID users have no local password |

`name` is filled from `given_name` and must already exist (it does in the default Laravel `users` table). If your table differs, edit the published migration before running it.

## Model

Add the attributes to `$fillable` and cast `spid_profile` to an array:

```php
<?php

namespace App\Models;

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

    protected $hidden = [
        'password',
        'remember_token',
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

What happens when something is missing:

- An attribute missing from `$fillable` among `name`, `surname`, `preferred_username`, `locale` and `zoneinfo` is silently not saved.
- `fiscal_code`, `email` and `spid_profile` are set with `setAttribute()`, so they are saved even when they are not in `$fillable`.
- If your app enables `Model::preventSilentlyDiscardingAttributes()`, any of the attributes above missing from `$fillable` throws a `MassAssignmentException`, including `fiscal_code` (the lookup `firstOrNew(['fiscal_code' => ...])` mass-assigns it when creating a new user). List them all in `$fillable`.
- Without the `array` cast on `spid_profile`, saving fails with "Array to string conversion".

## Reading the SPID data later

```php
$user = auth()->user();

$fiscalCode = $user->fiscal_code;                                   // TINIT-RSSMRA80A01H501U
$spidLevel = $user->spid_profile['enti-acr']['acr'] ?? null;        // https://www.spid.gov.it/SpidL2
```

The keys of `spid_profile` are listed in [user DTO](user-dto.md#toarray-shape). The claim names are still to be confirmed against a real AAC response, see [KI-01](known-issues.md#ki-01-aac-claim-names-are-not-verified-against-a-real-userinfo-response).

## Customizing

To change how users are matched or created (for example to assign roles or reject users), override `authenticateFromSpid()` in your own controller. See [extending](extending.md).
