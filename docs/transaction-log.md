# Transaction log

The SPID/CIE OIDC technical rules ([log management](https://docs.italia.it/italia/spid/spid-cie-oidc-docs/it/versione-corrente/log_management.html)) require every Relying Party to keep a register of the OIDC messages exchanged during authentication, encrypted, with integrity and non-repudiation guarantees, for **at least 24 months**. The package writes this register for you.

## Setup

1. Publish and run the migrations (the transaction log table is created by the same tag as the user columns):

   ```bash
   php artisan vendor:publish --tag=spid-laravel-trentino-migrations
   php artisan migrate
   ```

2. Schedule the prune command in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('spid:prune-logs')->daily();
   Schedule::command('spid:check-logs')->hourly();   // see Monitoring
   ```

3. Keep `APP_KEY` safe and stable: it encrypts the payloads and keys their HMAC (see [key rotation](#key-rotation)).

The log is enabled by default. If the table does not exist yet, logins keep working and each failed write logs `[SPID] Transaction log write failed`.

## Configuration

| Key | Env var | Default | Effect |
|-----|---------|---------|--------|
| `transaction_log.enabled` | `SPID_TRENTINO_TRANSACTION_LOG_ENABLED` | `true` | `false` (or `0`) disables writing, for local development and tests only |
| `transaction_log.table` | `SPID_TRENTINO_TRANSACTION_LOG_TABLE` | `spid_transaction_logs` | Table used by the migration and the model |
| `transaction_log.retention_months` | `SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS` | `24` | Retention used by `spid:prune-logs`; values below 24 are raised to 24 |
| `transaction_log.fail_closed` | `SPID_TRENTINO_TRANSACTION_LOG_FAIL_CLOSED` | `false` | `true` aborts logins and token refreshes whose row cannot be written (see [failure handling](#failure-handling)) |

## What is recorded

Each OIDC message becomes one row of `OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog`. The rows of one login share a `transaction_id` (a UUID stored in the session under `SessionKeys::TRANSACTION_ID` when the login starts).

| Event type | Written by | Payload |
|------------|------------|---------|
| `authentication_request` | `SpidTrentino::redirectToLogin()` | authorization endpoint and the request parameters (client id, redirect URI, scope, state, nonce, PKCE challenge) |
| `authentication_response` | `SpidTrentino::handleCallback()`, before the code exchange | `code`, `state`, or `error` and `error_description`; written for failed callbacks too |
| `token_request` | `handleCallback()`, after a successful exchange | grant type, code, client id, redirect URI (never the client secret) |
| `token_response` | `handleCallback()` | the token response with `access_token` and `refresh_token` replaced by `sha256:<hash>`; the signed `id_token` is kept as evidence |
| `userinfo_request`, `userinfo_response` | `handleCallback()` | the request marker and the userinfo claims |
| `refresh_request`, `refresh_response` | `SpidTrentino::refreshAccessToken()` | grant type and client id; the redacted token response |
| `logout` | `SpidTrentino::logout()` | the OIDC `sub` |

`revocation_request` and `revocation_response` are accepted event types; the package does not revoke tokens itself.

Searchable columns (indexed, stored in clear): `transaction_id`, `event_type`, `authorization_code`, `client_id`, `jti`, `iss`, `sub`, `iat`, `exp` (the last five taken from the verified ID token), plus `ip_address`, `user_agent`, `created_at`.

## Protection

- **Encryption at rest:** the JSON payload uses Laravel's `encrypted` cast (AES-256-CBC with `APP_KEY`).
- **Integrity:** `payload_hmac` is an HMAC-SHA256 of the plaintext JSON keyed with `APP_KEY`. `$record->verifyIntegrity()` returns `false` when the payload or the HMAC was changed.
- **Non-repudiation:** the `id_token` stored in `token_response` is signed by AAC and can be verified against AAC's keys.
- **No usable credentials:** the client secret is never stored, and access and refresh tokens are stored only as SHA-256 hashes.
- **Failures are logged without data:** a write error is logged with the exception class only (database errors contain the bound values). Whether the login then continues depends on [`fail_closed`](#failure-handling).

Restrict database access to the table to the people authorized to consult it.

## Querying

```php
use OfflineAgency\SpidLaravelTrentino\Models\SpidTransactionLog;

$records = SpidTransactionLog::query()
    ->forTransaction($transactionId)
    ->orderBy('id')
    ->get();

foreach ($records as $record) {
    $record->event_type;
    $record->payloadData();       // decrypted message as an array
    $record->verifyIntegrity();   // true if untouched
}

SpidTransactionLog::query()->where('sub', $subject)->latest()->get();
```

## Failure handling

A row can fail to be written because the table is missing, the database is read-only or full, or the connection drops. Every failure is logged as `[SPID] Transaction log write failed` with the event type, the transaction id and the exception class. What happens next depends on `transaction_log.fail_closed`:

| | `false` (default, fail-open) | `true` (fail-closed) |
|---|---|---|
| Login route | Redirects to AAC | Redirects to `error_redirect_to` with `SPID login is temporarily unavailable. Please try again later.` |
| Callback | Logs the user in | Aborts before anything is written to the session; the user gets the same message. A failure before the code exchange stops it from being sent to AAC |
| Token refresh (`spid.refresh`) | Refreshes | Fails like a refused refresh: `spid.refresh` removes the tokens from the session, and `spid.valid` sends the user back to the SPID login when the access token expires |
| Logout | Logs the user out | Logs the user out (a log failure never keeps a user logged in) |

Fail-closed is for deployments where a login without a log record is not acceptable. It turns a database problem into a SPID outage, so pair it with [monitoring](#monitoring). Each abort is logged as `[SPID] Login aborted: the transaction log is unavailable`, without the database error.

## Monitoring

`spid:check-logs` checks that the log is usable and exits with code 1 when it is not:

```bash
php artisan spid:check-logs                          # defaults: --max-age=24h --sample=20
php artisan spid:check-logs --max-age=2h --sample=100
```

It fails when:

- `transaction_log.enabled` is `false`, or the table does not exist;
- a SPID login succeeded within `--max-age` (minutes, hours or days: `30m`, `24h`, `7d`) but the newest row is more than five minutes older than that login: rows are not being written;
- one of the `--sample` newest rows fails `verifyIntegrity()` or cannot be decrypted.

When no login succeeded within `--max-age`, it prints a warning and succeeds, because it cannot tell whether writes work. The time of the last successful login is kept in the default cache store (key `spid-laravel-trentino:last-login`), so the check works even when the database is the problem; with several servers, use a shared cache store.

Schedule it with an alert:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('spid:check-logs')
    ->hourly()
    ->onFailure(function () {
        // notify the people on call, for example with a Notification
    });
```

## Pruning

```bash
php artisan spid:prune-logs              # deletes records older than retention_months
php artisan spid:prune-logs --dry-run    # only counts them
php artisan spid:prune-logs --months=36  # overrides the configuration
```

The command never keeps less than 24 months: lower values (option or configuration) are raised to 24 with a warning.

## Key rotation

Payloads are encrypted and signed with the current `APP_KEY`. Laravel can still decrypt old rows after a rotation when the old key is listed in `APP_PREVIOUS_KEYS`, but `verifyIntegrity()` recomputes the HMAC with the current key, so rows written before the rotation no longer verify. See [KI-13](known-issues.md#ki-13-transaction-log-integrity-checks-depend-on-the-current-app_key).
