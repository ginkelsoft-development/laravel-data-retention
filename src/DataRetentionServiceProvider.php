<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention;

use Ginkelsoft\DataRetention\Concerns\Exportable;
use Ginkelsoft\DataRetention\Concerns\Forgettable;
use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Ginkelsoft\DataRetention\Console\BreachDeadlinesCommand;
use Ginkelsoft\DataRetention\Console\ExportSubjectCommand;
use Ginkelsoft\DataRetention\Console\ForgetSubjectCommand;
use Ginkelsoft\DataRetention\Console\GrantConsentCommand;
use Ginkelsoft\DataRetention\Console\ListBreachesCommand;
use Ginkelsoft\DataRetention\Console\RegisterBreachCommand;
use Ginkelsoft\DataRetention\Console\RunRetentionCommand;
use Ginkelsoft\DataRetention\Console\ShowBreachCommand;
use Ginkelsoft\DataRetention\Console\ShowConsentStatusCommand;
use Ginkelsoft\DataRetention\Console\WithdrawConsentCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Class DataRetentionServiceProvider
 *
 * Registers and bootstraps the Laravel Data Retention package.
 *
 * Responsibilities:
 * - Merge and publish the package configuration.
 * - Publish the `retention_log` and `forget_log` migrations.
 * - Register the `retention:run`, `retention:forget`, and
 *   `retention:export` Artisan commands.
 *
 * Typical installation:
 *
 * composer require ginkelsoft/laravel-data-retention
 * php artisan vendor:publish --tag=data-retention-config
 * php artisan vendor:publish --tag=data-retention-migrations
 * php artisan migrate
 *
 * After installation, any model using {@see HasRetention}
 * can declare a time-driven retention policy and will be processed by
 * `retention:run`. Models using {@see Forgettable} can additionally
 * be processed per subject by `retention:forget {subject}`. Models
 * using {@see Exportable} can be included in `retention:export
 * {subject}` for subject-access (GDPR art. 15) requests.
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
            __DIR__.'/../database/migrations/create_forget_log_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_forget_log_table.php'),
            __DIR__.'/../database/migrations/create_consent_log_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 2).'_create_consent_log_table.php'),
            __DIR__.'/../database/migrations/create_breach_register_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 3).'_create_breach_register_table.php'),
            __DIR__.'/../database/migrations/create_breach_event_log_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 4).'_create_breach_event_log_table.php'),
        ], 'data-retention-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunRetentionCommand::class,
                ForgetSubjectCommand::class,
                ExportSubjectCommand::class,
                GrantConsentCommand::class,
                WithdrawConsentCommand::class,
                ShowConsentStatusCommand::class,
                RegisterBreachCommand::class,
                ListBreachesCommand::class,
                ShowBreachCommand::class,
                BreachDeadlinesCommand::class,
            ]);
        }
    }
}
