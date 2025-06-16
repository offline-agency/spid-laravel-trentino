<?php
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

/*
|--------------------------------------------------------------------------
| Rotte SPID Trentino                                                     |
|--------------------------------------------------------------------------
*/

$controller = config('spid.auth_controller', SpidAuthController::class);
$routes     = config('spid.routes');

/* ---------------------- LOGIN ---------------------- *
 * Redirects the user to the AAC / IdP login endpoint.              */
Route::get($routes['login'], function (SpidTrentino $spid) {
  return $spid->redirectToLogin();                 // genera HTTP 302 → IdP
})->name('spid.login');

/* -------------------- CALLBACK --------------------- *
 * Handled by the controller (default or custom) to:             *
 *   - complete the AAC transaction                              *
 *   - authenticate the user via the trait                       *
 *   - redirect using redirectTo() / intended()
 */
Route::get($routes['callback'], [$controller, 'callback'])
  ->name('spid.callback');

/* -------------------- LOGOUT ----------------------- */
Route::post($routes['logout'], [$controller, 'logout'])
  ->name('spid.logout');
