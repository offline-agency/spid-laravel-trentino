<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoFacade;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoServiceProvider;
use OfflineAgency\SpidLaravelTrentino\Tests\Fixtures\User;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    protected function getPackageProviders($app): array
    {
        return [SpidTrentinoServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SpidTrentino' => SpidTrentinoFacade::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('auth.providers.users.model', User::class);

        $config->set('spid-laravel-trentino.client_id', 'test-client');
        $config->set('spid-laravel-trentino.client_secret', 'test-secret');
        $config->set('spid-laravel-trentino.provider_url', 'https://aac.test');
        $config->set('spid-laravel-trentino.redirect_uri', 'https://app.test/spid/callback');

        // The package only publishes its migration; tests run it as part of
        // RefreshDatabase's migrate:fresh (Testbench under Pest skips
        // defineDatabaseMigrations()).
        $app->resolving('migrator', function (Migrator $migrator): void {
            $migrator->path(__DIR__.'/../database/migrations');
        });
    }
}
