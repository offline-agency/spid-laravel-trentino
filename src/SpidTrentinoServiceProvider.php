<?php

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
      // Config publishing
      $this->publishes([
        __DIR__ . '/../config/config.php' => config_path('spid-trentino.php'),
      ], 'config');

    }
    $this->loadRoutesFrom(__DIR__.'/../routes/spid-trentino-auth.php');
    $this->loadViewsFrom(__DIR__ . '/../resources/views', 'spid-laravel-trentino');

  }

  /**
   * Register the application services.
   */
  public function register(): void
  {
    // Merge default config
    $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'spid-trentino');

    // Bind the main service to the container
    $this->app->singleton('spid-trentino.auth', function ($app) {
      return $app->make(SpidTrentino::class);
    });
  }
}
