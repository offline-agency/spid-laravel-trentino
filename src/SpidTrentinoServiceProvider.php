<?php

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\PruneSpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Services\SpidTransactionLogger;

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
        __DIR__ . '/../config/config.php' => config_path('spid-laravel-trentino.php'),
      ], 'config');

      // Migration publishing
      $this->publishes([
        __DIR__ . '/../database/migrations/' => database_path('migrations'),
      ], 'spid-migrations');

      $this->commands([PruneSpidTransactionLogs::class]);
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
    $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'spid-laravel-trentino');

    // Bind the main service to the container
    $this->app->singleton('spid-laravel-trentino.auth', function ($app) {
      return $app->make(SpidTrentino::class);
    });

    // Transaction logger singleton
    $this->app->singleton(SpidTransactionLogger::class, fn () => new SpidTransactionLogger());
  }
}
