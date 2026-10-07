<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;

/*
|--------------------------------------------------------------------------
| SPID Laravel Trentino
|--------------------------------------------------------------------------
| Publish with:
|   php artisan vendor:publish --tag=spid-laravel-trentino-config
*/

return [

    /*
    | OIDC client credentials issued by AAC Trentino.
    */
    'client_id' => env('SPID_TRENTINO_CLIENT_ID'),
    'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),

    /*
    | Redirect URI registered on AAC. It must match the callback route exactly.
    | When null, the absolute URL of routes.callback is used.
    */
    'redirect_uri' => env('SPID_TRENTINO_REDIRECT_URI'),

    /*
    | AAC base URL. The discovery document is read from
    | {provider_url}/.well-known/openid-configuration.
    | The default is the AAC test environment: override it in production.
    */
    'provider_url' => env('SPID_TRENTINO_PROVIDER_URL', 'https://aac-test.cloud-test.tndigit.it'),

    /*
    | Space separated scopes ("openid" is always added).
    */
    'scopes' => env('SPID_TRENTINO_SCOPES', 'openid profile.codicefiscale.me email offline_access'),

    /*
    | Seconds the discovery document and the JWKS are cached (0 disables caching).
    */
    'cache_ttl' => 3600,

    /*
    | Recover a login whose callback arrives without the session cookie (for
    | example behind a proxy that strips cookies). The pending login is cached
    | for ten minutes, keyed by state and bound to the client IP address and
    | user agent, and can be used once. Off by default: read docs/security.md
    | before enabling it.
    */
    'session_fallback' => env('SPID_TRENTINO_SESSION_FALLBACK', false),

    /*
    | Transaction log required by the SPID/CIE OIDC retention policy: every
    | OIDC message of a login is stored encrypted (with APP_KEY), signed with an
    | HMAC and linked to the previous one by a hash chain, and must be kept for
    | at least 24 months. Publish and run the migrations, then schedule:
    |   Schedule::command('spid:prune-logs')->daily();
    |   Schedule::command('spid:log-digest')->dailyAt('00:30');
    |   Schedule::command('spid:verify-logs')->weekly();
    */
    'transaction_log' => [
        'enabled' => env('SPID_TRENTINO_TRANSACTION_LOG_ENABLED', true),
        'table' => env('SPID_TRENTINO_TRANSACTION_LOG_TABLE', 'spid_transaction_logs'),
        'retention_months' => env('SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS', 24),

        /*
        | Versioned HMAC keys as "id:secret,id2:secret2" (a secret may be
        | "base64:..."). New rows are signed with current_key; the id "app"
        | always means APP_KEY. Keep every key that signed rows still retained.
        */
        'keys' => env('SPID_TRENTINO_TRANSACTION_LOG_KEYS'),
        'current_key' => env('SPID_TRENTINO_TRANSACTION_LOG_CURRENT_KEY', 'app'),

        /*
        | Filesystem disk receiving the daily chain digests (spid:log-digest),
        | ideally write-once storage. null disables the digests.
        */
        'digest_disk' => env('SPID_TRENTINO_TRANSACTION_LOG_DIGEST_DISK'),
    ],

    /*
    | Set to false to register your own routes named spid.login, spid.callback
    | and spid.logout.
    */
    'register_routes' => true,

    'routes' => [
        'login' => '/spid/login',
        'callback' => '/spid/callback',
        'logout' => '/spid/logout',
    ],

    /*
    | Controller handling login, callback and logout. Extend SpidAuthController
    | to customize how users are created or authenticated.
    */
    'auth_controller' => SpidAuthController::class,

    /*
    | Path used after a successful login when there is no intended URL.
    | null uses SpidAuthenticatesUsers::redirectTo() (admins to /admin/dashboard, others to /).
    */
    'redirect_to' => env('SPID_TRENTINO_REDIRECT_TO'),

    /*
    | Path used after logout.
    */
    'logout_redirect_to' => '/',

    /*
    | Path used when the SPID login fails. The message is flashed to the
    | session under OfflineAgency\SpidLaravelTrentino\SessionKeys::ERROR.
    */
    'error_redirect_to' => '/',
];
