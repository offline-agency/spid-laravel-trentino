<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;

$controller = Config::get('spid-laravel-trentino.auth_controller', SpidAuthController::class);

Route::middleware('web')->group(function () use ($controller): void {
    Route::get(Config::string('spid-laravel-trentino.routes.login'), [$controller, 'login'])->name('spid.login');
    Route::get(Config::string('spid-laravel-trentino.routes.callback'), [$controller, 'callback'])->name('spid.callback');
    Route::post(Config::string('spid-laravel-trentino.routes.logout'), [$controller, 'logout'])->name('spid.logout');
});
