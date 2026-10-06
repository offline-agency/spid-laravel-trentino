<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\ServiceProvider;

class SpidTrentinoServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spid-laravel-trentino.php' => $this->app->configPath('spid-laravel-trentino.php'),
            ], 'spid-laravel-trentino-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/spid-laravel-trentino'),
            ], 'spid-laravel-trentino-views');
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/spid-trentino-auth.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spid-laravel-trentino');

    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Merge default config
        $this->mergeConfigFrom(__DIR__.'/../config/spid-laravel-trentino.php', 'spid-laravel-trentino');

        // Bind the main service to the container
        $this->app->singleton('spid-laravel-trentino.auth', function ($app) {
            return $app->make(SpidTrentino::class);
        });
    }
}
