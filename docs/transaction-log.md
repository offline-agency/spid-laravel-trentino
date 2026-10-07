# Transaction log

The SPID/CIE OIDC technical rules ([log management](https://docs.italia.it/italia/spid/spid-cie-oidc-docs/it/versione-corrente/log_management.html)) require every Relying Party to keep a register of the OIDC messages exchanged during authentication, encrypted, with integrity and non-repudiation guarantees, for **at least 24 months**. The package writes this register for you.

## Setup

1. Publish and run the migrations (the transaction log tables are created by the same tag as the user columns):

   ```bash
   php artisan vendor:publish --tag=spid-laravel-trentino-migrations
   php artisan migrate
   ```

2. Schedule the maintenance commands in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('spid:prune-logs')->daily();
   Schedule::command('spid:log-digest')->dailyAt('00:30');   // when digest_disk is set
   Schedule::command('spid:verify-logs')->weekly();
   ```

3. Keep `APP_KEY` and the HMAC keys safe, stable and escrowed (see [keys and rotation](#keys-and-rotation)).

The log is enabled by default. If the table does not exist yet, logins keep working and each failed write logs `[SPID] Transaction log write failed`.

## Configuration

| Key | Env var | Default | Effect |
|-----|---------|---------|--------|
| `transaction_log.enabled` | `SPID_TRENTINO_TRANSACTION_LOG_ENABLED` | `true` | `false` (or `0`) disables writing, for local development and tests only |
| `transaction_log.table` | `SPID_TRENTINO_TRANSACTION_LOG_TABLE` | `spid_transaction_logs` | Table used by the migrations and the model; the chain head and the prune checkpoints use `<table>_heads` and `<table>_checkpoints` |
| `transaction_log.retention_months` | `SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS` | `24` | Retention used by `spid:prune-logs`; values below 24 are raised to 24 |
| `transaction_log.keys` | `SPID_TRENTINO_TRANSACTION_LOG_KEYS` | `null` | Versioned HMAC keys as `id:secret,id2:secret2`; a secret may be `base64:...`; ids use letters, digits, `.`, `_` and `-` (up to 32 characters) |
| `transaction_log.current_key` | `SPID_TRENTINO_TRANSACTION_LOG_CURRENT_KEY` | `app` | Id of the key that signs new rows; `app` means `APP_KEY` |
| `transaction_log.digest_disk` | `SPID_TRENTINO_TRANSACTION_LOG_DIGEST_DISK` | `null` | Filesystem disk receiving the daily digests of `spid:log-digest`; `null` disables them |

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

Searchable columns (indexed, stored in clear): `transaction_id`, `event_type`, `authorization_code`, `client_id`, `jti`, `iss`, `sub`, `iat`, `exp` (the last five taken from the verified ID token), plus `ip_address`, `user_agent`, `created_at`. Each row also stores `key_id`, `previous_hash` and `chain_hash` (see [hash chain](#hash-chain)).

## Protection

- **Encryption at rest:** the JSON payload uses Laravel's `encrypted` cast (AES-256-CBC with `APP_KEY`).
- **Integrity:** `payload_hmac` is an HMAC-SHA256 of the plaintext JSON keyed with the current HMAC key, whose id is stored in `key_id`. `$record->verifyIntegrity()` recomputes it with that key and returns `false` when the payload or the HMAC was changed, or when the key is no longer configured.
- **Tamper evidence:** every row is linked to the previous one by a SHA-256 [hash chain](#hash-chain), so deleting, inserting or editing rows breaks `spid:verify-logs`. Publishing the daily [digests](#daily-digests) to write-once storage makes rewriting the whole chain detectable too.
- **Non-repudiation:** the `id_token` stored in `token_response` is signed by AAC and can be verified against AAC's keys.
- **No usable credentials:** the client secret is never stored, and access and refresh tokens are stored only as SHA-256 hashes.
- **Failures never block logins:** a write error is logged with the exception class only (database errors contain the bound values) and the login continues.

Restrict database access to the tables to the people authorized to consult them.

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

## Keys and rotation

Two kinds of keys protect the log:

| Key | Used for | Rotation |
|-----|----------|----------|
| `APP_KEY` | Encrypting the payload (Laravel `encrypted` cast), and the HMAC of rows signed with the key id `app` | List the old key in `APP_PREVIOUS_KEYS` for as long as rows encrypted or signed with it are retained (24 months or more): it is used both to decrypt and to verify them |
| HMAC keys (`transaction_log.keys`) | `payload_hmac` of each row, recorded by id in `key_id` | Add a new id, switch `current_key` to it, keep the old ids configured while their rows are retained |

The key id `app` always means `APP_KEY` (the raw string, exactly as earlier versions used it), and rows without a `key_id` were signed with it. Until you configure your own keys, new rows keep using `app`. Rows signed with `app` are verified with the current `APP_KEY` and then with each key in `APP_PREVIOUS_KEYS`, so they stay verifiable after an `APP_KEY` rotation as long as the old key is listed there.

To move to a dedicated HMAC key, or to rotate it:

```dotenv
SPID_TRENTINO_TRANSACTION_LOG_KEYS="2026a:base64:Q2hhbmdlIG1lIHRvIDMyIHJhbmRvbSBieXRlcyE="
SPID_TRENTINO_TRANSACTION_LOG_CURRENT_KEY=2026a

# a year later
SPID_TRENTINO_TRANSACTION_LOG_KEYS="2026a:base64:...,2027a:base64:..."
SPID_TRENTINO_TRANSACTION_LOG_CURRENT_KEY=2027a
```

Generate secrets with `php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`. An id that is malformed, or a secret that is empty or not valid base64, is ignored; if `current_key` names a key that is not configured, writing a row fails (and is logged) instead of signing with a wrong key. With dedicated HMAC keys, rotating `APP_KEY` only affects decryption.

**Key escrow.** A row can only be verified while its HMAC key is available, and only be read while its `APP_KEY` is available. Store every key that protected retained rows (current and previous `APP_KEY`, every HMAC key id and secret) in a secrets manager or a sealed escrow held by someone other than the application operators, and keep it for the whole retention period. Losing a key does not delete rows, but they can no longer be proven intact or decrypted.

## Hash chain

Every row stores the chain hash of the row written before it (`previous_hash`) and its own `chain_hash`:

```
chain_hash = sha256(previous_hash + "\n" + payload_hmac + "\n" + canonical JSON of the searchable columns, key_id and created_at)
```

The first chained row links to `sha256("spid-laravel-trentino:genesis")`. The hash is plain SHA-256, not keyed: anyone holding the rows and the digests can check the links without any secret. Appends are serialized through the single row of `<table>_heads` (created by the migration): each write locks it, then reads the current head with a locking read, so concurrent logins never link to the same row. Outside an application transaction, a write that hits lock contention is attempted up to three times.

The lock is held until the surrounding transaction commits. The package writes the log outside transactions; if you call `SpidTransactionLog::log()` inside your own transaction, keep that transaction short, because every other login waits for it.

## Verification

```bash
php artisan spid:verify-logs                                    # the whole log, up to the chain head
php artisan spid:verify-logs --from=2026-03-01 --to=2026-03-31  # one month
```

For every row the command checks the HMAC, that `previous_hash` matches the row before it and that `chain_hash` matches the row. It stops at the first row that fails, prints `Row #<id> failed verification: <reason>` and exits with code 1 (code 2 for invalid dates). Without `--to` it also checks that the chain head recorded when the command started still exists with the same hash, which detects deleted newest rows; rows appended while the command runs are not reported. With `--from`, the first row is checked against the row before it (or the prune checkpoint), whose own integrity is not checked.

Rows written before the hash chain existed are reported as legacy: only their HMAC is checked, and they must all come before the first chained row.

## Daily digests

Set `transaction_log.digest_disk` to a disk the application can write to but not rewrite, then schedule `spid:log-digest`. Each run writes `spid-transaction-log/digests/YYYY-MM-DD.json` for the previous day (or `--date=YYYY-MM-DD`, which must be before today):

```json
{
    "date": "2026-03-02",
    "rows": 418,
    "first_id": 90211,
    "last_id": 90628,
    "last_chain_hash": "5f0c...",
    "key_ids": ["2026a"],
    "generated_at": "2026-03-03T00:30:00+00:00"
}
```

A day without rows gets a digest with `rows: 0` and `null` ids and hash. An existing digest is never overwritten. To check the log against a digest, verify the rows up to that day and compare the `chain_hash` of row `last_id` with `last_chain_hash`.

A suitable target is an S3 bucket with [Object Lock](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html) in compliance mode and a retention of at least the log retention, written with credentials that cannot delete objects or change the retention:

```php
// config/filesystems.php
'spid-digests' => [
    'driver' => 's3',
    'key' => env('SPID_DIGESTS_ACCESS_KEY_ID'),
    'secret' => env('SPID_DIGESTS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('SPID_DIGESTS_BUCKET'),
],
```

```dotenv
SPID_TRENTINO_TRANSACTION_LOG_DIGEST_DISK=spid-digests
```

Without the digests, someone with write access to the database could rebuild the whole chain after changing a row; with them, the rebuilt chain no longer matches the published hashes.

## Pruning

```bash
php artisan spid:prune-logs              # deletes records older than retention_months
php artisan spid:prune-logs --dry-run    # only counts them
php artisan spid:prune-logs --months=36  # overrides the configuration
```

The command never keeps less than 24 months: lower values (option or configuration) are raised to 24 with a warning.

Only the oldest contiguous run of expired rows is deleted: an expired row written after a row that must be kept (for example because of clock skew between servers) stays until the rows before it expire, so the remaining chain has no gaps. Each prune that deletes rows records a checkpoint in `<table>_checkpoints` (last deleted id, its chain hash and creation time, number of rows deleted); `spid:verify-logs` starts from the newest checkpoint.

## Upgrading an existing log

Logs created before the hash chain existed need the new migration (it adds `key_id`, `previous_hash` and `chain_hash` and creates the heads and checkpoints tables; existing rows are not modified):

```bash
php artisan vendor:publish --tag=spid-laravel-trentino-migrations
php artisan migrate
```

Existing rows keep their `APP_KEY` HMAC, stay outside the chain and are verified as legacy rows. The first row written after the upgrade starts the chain from the genesis hash. Configure dedicated HMAC keys afterwards, keeping `APP_KEY` (id `app`) available for the legacy rows.
