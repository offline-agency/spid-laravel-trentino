<?php

/*
|--------------------------------------------------------------------------
| SPID Trentino – Package Configuration
|--------------------------------------------------------------------------
| Copy this file to your project with:
|   php artisan vendor:publish --tag=spid-config
| …then adjust the values or reference environment variables as needed.
*/

return [

  /*
  |--------------------------------------------------------------------------
  | OAuth / OIDC Client Credentials
  |--------------------------------------------------------------------------
  */
  'client_id'     => env('SPID_TRENTINO_CLIENT_ID'),
  'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),

  /*
  |--------------------------------------------------------------------------
  | Redirect URI registered on AAC / IdP
  |--------------------------------------------------------------------------
  */
  'redirect_uri'  => env('SPID_TRENTINO_REDIRECT_URI'),

  /*
  |--------------------------------------------------------------------------
  | Provider (AAC) Base URL
  |--------------------------------------------------------------------------
  | Default points to the public test environment.
  | Override in .env for production.
  */
  'provider_url'  => env(
    'SPID_TRENTINO_PROVIDER_URL',
    'https://aac-test.cloud-test.tndigit.it'
  ),

  /*
  |--------------------------------------------------------------------------
  | Scopes requested during authentication
  |--------------------------------------------------------------------------
  */
  'scopes' => env(
    'SPID_TRENTINO_SCOPES',
    'openid profile.codicefiscale.me email offline_access'
  ),

  /*
  |--------------------------------------------------------------------------
  | Route paths (relative to the app URL)
  |--------------------------------------------------------------------------
  | You can freely change these; just keep them in sync with the AAC
  | configuration for “Login callback”.
  */
  'routes' => [
    'login'    => '/spid/login',
    'callback' => '/spid/callback',
    'logout'   => '/logout',
  ],

  /*
  |--------------------------------------------------------------------------
  | Controller that handles SPID login / logout
  |--------------------------------------------------------------------------
  | Swap this FQN for your own controller (it may or may not use the
  | SpidAuthenticatesUsers trait) and run `php artisan config:clear`
  | to apply the change.
  */
  'auth_controller' =>
    \OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController::class,

  /*
  |--------------------------------------------------------------------------
  | Redirect path after a successful login
  |--------------------------------------------------------------------------
  | Absolute path (string). If set to null, the dynamic logic defined in
  | SpidAuthenticatesUsers::redirectTo() will be used instead.
  */
  'redirect_to' => '/dashboard', // or null

  /*
  |--------------------------------------------------------------------------
  | Transaction Log (SPID Retention Policy compliance)
  |--------------------------------------------------------------------------
  | The Italian SPID/CIE OIDC regulations mandate that every Relying Party
  | maintain an encrypted transaction log retained for at least 24 months.
  |
  | enabled          – Set to false to disable logging (dev/test only).
  | table            – Database table name for the transaction log.
  | retention_months – Minimum months to retain records (floor: 24).
  |
  | Schedule the prune command in your console kernel:
  |   $schedule->command('spid:prune-logs')->daily();
  */
  'transaction_log' => [
    'enabled'          => env('SPID_TRANSACTION_LOG_ENABLED', true),
    'table'            => env('SPID_TRANSACTION_LOG_TABLE', 'spid_transaction_logs'),
    'retention_months' => env('SPID_TRANSACTION_LOG_RETENTION_MONTHS', 24),
  ],
];
