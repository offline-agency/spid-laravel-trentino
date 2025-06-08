<?php

use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;

Route::get(config('spid-laravel-trentino.routes.login', '/login'), function (SpidTrentino $aac) {
  return $aac->redirectToLogin();
})->name('spid-laravel-trentino.login');

Route::get(config('spid-laravel-trentino.routes.callback', '/callback'), function (SpidTrentino $aac) {
  $aac->handleCallback();
  return redirect()->intended('/');
})->name('spid-laravel-trentino.callback');

Route::post(config('spid-laravel-trentino.routes.logout', '/logout'), function (SpidTrentino $aac) {
  $aac->logout();
  return redirect('/');
})->name('spid-laravel-trentino.logout');
