<?php

use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

/*
|--------------------------------------------------------------------------
| Rotte SPID Trentino                                                     |
|--------------------------------------------------------------------------
*/

$controller = config('spid-laravel-trentino.auth_controller', SpidAuthController::class);
$routes = config('spid-laravel-trentino.routes');

/* ---------------------- LOGIN ---------------------- *
 * Redirects the user to the AAC / IdP login endpoint.              */
Route::get($routes['login'], function (SpidTrentino $spid) {
    return $spid->redirectToLogin();                 // genera HTTP 302 → IdP
})
    ->middleware(['web'])
    ->name('spid.login');

/* -------------------- CALLBACK --------------------- *
 * Handled by the controller (default or custom) to:             *
 *   - complete the AAC transaction                              *
 *   - authenticate the user via the trait                       *
 *   - redirect using redirectTo() / intended()
 */
Route::get($routes['callback'], [$controller, 'callback'])
    ->middleware(['web'])
    ->name('spid.callback');

/* -------------------- LOGOUT ----------------------- */
Route::post($routes['logout'], [$controller, 'logout'])
    ->middleware(['web'])
    ->name('spid.logout');
