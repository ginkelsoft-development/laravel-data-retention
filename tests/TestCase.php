<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests;

use Ginkelsoft\DataRetention\DataRetentionServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base TestCase wiring up Orchestra Testbench with the package
 * service provider and a fresh in-memory SQLite database for each test.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Register the package service provider with Testbench.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DataRetentionServiceProvider::class];
    }

    /**
     * Configure a deterministic test environment.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('data-retention.log_secret', 'test-log-secret');
    }

    /**
     * Run the package migrations before each test.
     *
     * @param  Application  $app
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
