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
   ```

3. Keep `APP_KEY` safe and stable: it encrypts the payloads and keys their HMAC (see [key rotation](#key-rotation)).

The log is enabled by default. If the table does not exist yet, logins keep working and each failed write logs `[SPID] Transaction log write failed`.

## Configuration

| Key | Env var | Default | Effect |
|-----|---------|---------|--------|
| `transaction_log.enabled` | `SPID_TRENTINO_TRANSACTION_LOG_ENABLED` | `true` | `false` (or `0`) disables writing, for local development and tests only |
| `transaction_log.table` | `SPID_TRENTINO_TRANSACTION_LOG_TABLE` | `spid_transaction_logs` | Table used by the migration and the model |
| `transaction_log.retention_months` | `SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS` | `24` | Retention used by `spid:prune-logs`; values below 24 are raised to 24 |

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
- **Failures never block logins:** a write error is logged with the exception class only (database errors contain the bound values) and the login continues.

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

## Unauthenticated requests

`authentication_request` and `authentication_response` are written before the user is authenticated, so the login and callback routes are [rate limited](security.md#rate-limiting) to keep bots from growing the log. An `authentication_request` without a callback (the user never came back from AAC) is kept for the same retention as every other row. Whether such orphan rows may be pruned earlier is a decision for your data protection officer; the package does not do it.

## Pruning

```bash
php artisan spid:prune-logs              # deletes records older than retention_months
php artisan spid:prune-logs --dry-run    # only counts them
php artisan spid:prune-logs --months=36  # overrides the configuration
```

The command never keeps less than 24 months: lower values (option or configuration) are raised to 24 with a warning.

## Key rotation

Payloads are encrypted and signed with the current `APP_KEY`. Laravel can still decrypt old rows after a rotation when the old key is listed in `APP_PREVIOUS_KEYS`, but `verifyIntegrity()` recomputes the HMAC with the current key, so rows written before the rotation no longer verify. See [KI-13](known-issues.md#ki-13-transaction-log-integrity-checks-depend-on-the-current-app_key).
