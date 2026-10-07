<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\PruneSpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\VerifySpidTransactionLogs;
use OfflineAgency\SpidLaravelTrentino\Console\Commands\WriteSpidTransactionLogDigest;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\EnsureValidSpidToken;
use OfflineAgency\SpidLaravelTrentino\Http\Middleware\RefreshSpidTokenIfNeeded;
use OfflineAgency\SpidLaravelTrentino\OpenIdConnect\LaravelOpenIDConnectClient;

class SpidTrentinoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spid-laravel-trentino.php', 'spid-laravel-trentino');

        $this->app->bind(LaravelOpenIDConnectClient::class, fn (): LaravelOpenIDConnectClient => $this->makeClient());
        $this->app->scoped(SpidTrentino::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('spid.valid', EnsureValidSpidToken::class);
        $router->aliasMiddleware('spid.refresh', RefreshSpidTokenIfNeeded::class);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spid-laravel-trentino');

        if (Config::boolean('spid-laravel-trentino.register_routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/spid-trentino-auth.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spid-laravel-trentino.php' => $this->app->configPath('spid-laravel-trentino.php'),
            ], 'spid-laravel-trentino-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/spid-laravel-trentino'),
            ], 'spid-laravel-trentino-views');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'spid-laravel-trentino-migrations');

            $this->commands([PruneSpidTransactionLogs::class, VerifySpidTransactionLogs::class, WriteSpidTransactionLogDigest::class]);
        }
    }

    private function makeClient(): LaravelOpenIDConnectClient
    {
        $secret = Config::get('spid-laravel-trentino.client_secret');
        $cacheTtl = Config::get('spid-laravel-trentino.cache_ttl');
        $redirectUri = Config::get('spid-laravel-trentino.redirect_uri');
        $scopes = preg_split('/\s+/', Config::string('spid-laravel-trentino.scopes'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $client = new LaravelOpenIDConnectClient(
            Config::string('spid-laravel-trentino.provider_url'),
            Config::string('spid-laravel-trentino.client_id'),
            is_string($secret) && $secret !== '' ? $secret : null,
            is_numeric($cacheTtl) ? (int) $cacheTtl : 3600,
            filter_var(Config::get('spid-laravel-trentino.session_fallback'), FILTER_VALIDATE_BOOL),
        );

        $client->setRedirectURL(is_string($redirectUri) && $redirectUri !== ''
            ? $redirectUri
            : URL::to(Config::string('spid-laravel-trentino.routes.callback')));
        // "openid" is always added by the client.
        $client->addScope(array_values(array_diff($scopes, ['openid'])));
        $client->setCodeChallengeMethod('S256');

        return $client;
    }
}
