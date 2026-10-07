<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use OfflineAgency\SpidLaravelTrentino\Http\Controllers\SpidAuthController;
use OfflineAgency\SpidLaravelTrentino\Support\RouteThrottle;

$controller = Config::get('spid-laravel-trentino.auth_controller', SpidAuthController::class);
$throttle = RouteThrottle::parse(Config::get('spid-laravel-trentino.throttle')) !== null
    ? [ThrottleRequests::using(RouteThrottle::LIMITER)]
    : [];

Route::middleware('web')->group(function () use ($controller, $throttle): void {
    Route::get(Config::string('spid-laravel-trentino.routes.login'), [$controller, 'login'])->name('spid.login')->middleware($throttle);
    Route::get(Config::string('spid-laravel-trentino.routes.callback'), [$controller, 'callback'])->name('spid.callback')->middleware($throttle);
    Route::post(Config::string('spid-laravel-trentino.routes.logout'), [$controller, 'logout'])->name('spid.logout');
});
