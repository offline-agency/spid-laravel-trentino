# Events

| Event | Dispatched by | When |
|-------|---------------|------|
| `OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn` | `SpidTrentino::handleCallback()` | After AAC authenticated the user and the tokens and SPID user were stored in the session, **before** the local user is created or logged in. |
| `OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut` | `SpidTrentino::logout()` | After the application session was ended, only if the session held a SPID user with a fiscal code. |

The default controller uses both: `callback()` calls `handleCallback()`, and `logout()` (the `spid.logout` route) calls `SpidTrentino::logout()`.

Because `SpidTrentinoLoggedIn` fires before `authenticateFromSpid()`, `auth()->user()` is not yet the SPID user inside its listeners. Listen to Laravel's `Illuminate\Auth\Events\Login` when you need the local user model.

## Payload

Both events carry the SPID identity as a `OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser`:

```php
$event->user;        // public readonly SpidTrentinoUser
$event->getUser();   // same object
```

See [user DTO](user-dto.md) for the available getters. The events do not use `Dispatchable` or `SerializesModels`; the DTO is a plain object and serializes on its own, so queued listeners work.

## Listening with a closure

In `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Support\LogRedactor;

public function boot(): void
{
    Event::listen(function (SpidTrentinoLoggedIn $event) {
        Log::info('SPID login', LogRedactor::user($event->user));
    });
}
```

`LogRedactor::user()` returns keyed hashes of the subject and fiscal code, so the log identifies the person without storing personal data (see [security](security.md#logging-and-personal-data)).

## Listening with a class

Laravel 12 and 13 discover listeners in `app/Listeners` by the type of the `handle()` argument:

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;

class RecordSpidLogout implements ShouldQueue
{
    public function handle(SpidTrentinoLoggedOut $event): void
    {
        $fiscalCode = $event->user->getFiscalNumber();

        // for example: write an audit record
    }
}
```

If event discovery is disabled in your application, register it with `Event::listen(SpidTrentinoLoggedOut::class, RecordSpidLogout::class);`.

## Dispatching manually

The events have no static `dispatch()` helper. Use the `event()` helper or the `Event` facade:

```php
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

event(new SpidTrentinoLoggedIn(SpidTrentinoUser::fromArray($payload)));
```
