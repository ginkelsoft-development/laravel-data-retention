<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention;

use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Ginkelsoft\DataRetention\Console\RunRetentionCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Data Retention package.
 *
 * Responsibilities:
 * - Merge and publish the package configuration.
 * - Publish and load the `retention_log` migration.
 * - Register the `retention:run` Artisan command.
 *
 * Typical installation:
 *
 *   composer require ginkelsoft/laravel-data-retention
 *   php artisan vendor:publish --tag=data-retention-config
 *   php artisan vendor:publish --tag=data-retention-migrations
 *   php artisan migrate
 *
 * After installation, any model using {@see HasRetention} can declare
 * a time-driven retention policy and will be processed by `retention:run`.
 *
 * Sibling packages in the GinkelSoft compliance family handle the other
 * GDPR controls (right to be forgotten, subject access, consent, breach
 * registry). Install `ginkelsoft/laravel-compliance-hub` to get the whole
 * family in one go.
 */
class DataRetentionServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings and configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/data-retention.php',
            'data-retention'
        );
    }

    /**
     * Bootstrap the package: publishing, migrations, and commands.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/data-retention.php' => config_path('data-retention.php'),
        ], 'data-retention-config');

        $timestamp = date('Y_m_d_His');
        $this->publishes([
            __DIR__.'/../database/migrations/create_retention_log_table.php' => database_path("migrations/{$timestamp}_create_retention_log_table.php"),
        ], 'data-retention-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunRetentionCommand::class,
            ]);
        }
    }
}
