<?php

use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;

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
    'client_id' => env('SPID_TRENTINO_CLIENT_ID'),
    'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),

    /*
  |--------------------------------------------------------------------------
  | Redirect URI registered on AAC / IdP
  |--------------------------------------------------------------------------
  */
    'redirect_uri' => env('SPID_TRENTINO_REDIRECT_URI'),

    /*
  |--------------------------------------------------------------------------
  | Provider (AAC) Base URL
  |--------------------------------------------------------------------------
  | Default points to the public test environment.
  | Override in .env for production.
  */
    'provider_url' => env(
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
        'login' => '/spid/login',
        'callback' => '/spid/callback',
        'logout' => '/logout',
    ],

    /*
  |--------------------------------------------------------------------------
  | Controller that handles SPID login / logout
  |--------------------------------------------------------------------------
  | Swap this FQN for your own controller (it may or may not use the
  | SpidAuthenticatesUsers trait) and run `php artisan config:clear`
  | to apply the change.
  */
    'auth_controller' => SpidAuthController::class,

    /*
  |--------------------------------------------------------------------------
  | Redirect path after a successful login
  |--------------------------------------------------------------------------
  | Absolute path (string). If set to null, the dynamic logic defined in
  | SpidAuthenticatesUsers::redirectTo() will be used instead.
  */
    'redirect_to' => '/dashboard', // or null
];
