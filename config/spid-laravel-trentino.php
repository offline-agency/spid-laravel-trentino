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
    | OIDC message of a login is stored encrypted (with APP_KEY) and signed with
    | an HMAC, and must be kept for at least 24 months. Publish and run the
    | migrations, then schedule the prune command:
    |   Schedule::command('spid:prune-logs')->daily();
    */
    'transaction_log' => [
        'enabled' => env('SPID_TRENTINO_TRANSACTION_LOG_ENABLED', true),
        'table' => env('SPID_TRENTINO_TRANSACTION_LOG_TABLE', 'spid_transaction_logs'),
        'retention_months' => env('SPID_TRENTINO_TRANSACTION_LOG_RETENTION_MONTHS', 24),
    ],

    /*
    | Minimum SPID level for a login: SpidL1, SpidL2 or SpidL3 (or the
    | https://www.spid.gov.it/SpidLn URI). When set, the level is requested
    | from AAC with acr_values and checked on the callback (higher levels are
    | accepted); a login whose level is lower or cannot be determined is
    | rejected. null accepts any level.
    */
    'required_acr' => env('SPID_TRENTINO_REQUIRED_ACR'),

    /*
    | Identity providers accepted for a login, as a comma separated list of
    | issuer sources (or an array). null accepts any.
    */
    'allowed_issuer_sources' => env('SPID_TRENTINO_ALLOWED_ISSUER_SOURCES'),

    /*
    | Where the SPID level and the identity provider are read, as ordered
    | "source:dot.path" lists: source is id_token (verified ID token claims) or
    | userinfo. The first non-empty value wins. Adjust them if AAC uses other
    | claim names (see docs/known-issues.md, KI-01).
    */
    'claims' => [
        'acr' => ['id_token:acr', 'userinfo:enti-acr.acr'],
        'issuer_source' => ['userinfo:enti-issuersource.issuerSource'],
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
