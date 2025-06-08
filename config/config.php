<?php

/*
 * You can place your custom package configuration in here.
 */
return [
  'client_id' => env('SPID_TRENTINO_CLIENT_ID'),
  'client_secret' => env('SPID_TRENTINO_CLIENT_SECRET'),
  'redirect_uri' => env('SPID_TRENTINO_REDIRECT_URI'),
  'provider_url' => env('SPID_TRENTINO_PROVIDER_URL', 'https://aac-test.cloud-test.tndigit.it'),
  'scopes' => env('SPID_TRENTINO_SCOPES', 'openid profile.codicefiscale.me email offline_access'),

  'routes' => [
    'login' => '/login',
    'callback' => '/callback',
    'logout' => '/logout',
  ],
];
