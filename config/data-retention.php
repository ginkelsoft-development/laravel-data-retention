<?php

declare(strict_types=1);

/**
 * -----------------------------------------------------------------------------
 * Ginkelsoft Laravel Data Retention - Configuration
 * -----------------------------------------------------------------------------
 *
 * This configuration file controls the time-driven retention sweep:
 * which models are processed, whether soft-deleted rows are included,
 * and the chunk size used when iterating large tables.
 *
 * The shared compliance signing secret (`log_secret`) and the default
 * placeholder values for the anonymize strategies live in the
 * `compliance` config provided by `ginkelsoft/laravel-compliance-core`.
 * For installations upgrading from the monolithic v1.x package, both
 * the `data-retention.log_secret` env var and the legacy
 * `data-retention.placeholders.*` keys remain readable via core's
 * `LogSecret` / `PlaceholderConfig` helpers, so no env changes are
 * required at upgrade time.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Retention Models Registry
    |--------------------------------------------------------------------------
    |
    | List the Eloquent model classes that participate in retention sweeps.
    | The `retention:run` command iterates this list. Models not listed here
    | are never touched by the package, even if they use HasRetention.
    |
    | Example:
    |   'models' => [
    |       \App\Models\Client::class,
    |       \App\Models\AuditEntry::class,
    |   ],
    |
    */
    'models' => [],

    /*
    |--------------------------------------------------------------------------
    | Include Soft-Deleted Records
    |--------------------------------------------------------------------------
    |
    | When a model uses SoftDeletes, this flag controls whether already
    | soft-deleted records are also considered for retention. Typically
    | true: soft-deleted rows still hold personal data and must also be
    | hard-deleted or anonymized when the retention period expires.
    |
    */
    'include_soft_deleted' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Chunk Size
    |--------------------------------------------------------------------------
    |
    | Records are processed in chunks to keep memory bounded on large tables.
    | Override per command invocation with `--chunk=`.
    |
    */
    'chunk_size' => 500,
];
