<?php

declare(strict_types=1);

/**
 * -----------------------------------------------------------------------------
 * Ginkelsoft Laravel Data Retention - Configuration
 * -----------------------------------------------------------------------------
 *
 * This configuration file controls how the data-retention system enforces
 * GDPR / AVG storage-limitation rules (art. 5(1)(e)) on Eloquent models.
 *
 * Per-model retention rules are declared directly on the model itself
 * (via the `HasRetention` trait, the `#[Retention]` attribute, or a
 * `$retention` property). This file holds package-wide defaults.
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
    | Forgettable Models Registry
    |--------------------------------------------------------------------------
    |
    | List the Eloquent model classes that participate in GDPR art. 17
    | "right to be forgotten" sweeps. The `retention:forget {subject}`
    | command iterates this list and applies each model's Forgettable
    | policy (delete or anonymize) to records belonging to the subject.
    |
    | Models in this list must use the `Forgettable` trait and declare
    | a policy via either the `#[Forgettable]` attribute or a
    | `$forgettable` array property.
    |
    | A model may appear in both `models` and `forgettable.models` —
    | retention covers time-based opruiming, forgettable covers
    | subject-based opruiming, the two are independent.
    |
    */
    'forgettable' => [
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exportable Models Registry
    |--------------------------------------------------------------------------
    |
    | List the Eloquent model classes that participate in GDPR art. 15
    | "subject access" exports. The `retention:export {subject}` command
    | iterates this list and collects every record belonging to the
    | subject across these models.
    |
    | Models in this list must use the `Exportable` trait, implement the
    | `Contracts\Exportable` interface, and declare a `$exportable`
    | property listing the fields to include in the export (explicit
    | opt-in; auto-including all columns is unsafe).
    |
    | A model may appear in any combination of `models`, `forgettable.models`,
    | and `exportable.models` — the three controls are independent.
    |
    */
    'exportable' => [
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Log Signing Key
    |--------------------------------------------------------------------------
    |
    | The audit log is tamper-evident: each entry contains a SHA-256 hash
    | that includes the previous entry's hash. This optional secret is mixed
    | into every hash so an attacker who can write to the database but does
    | not know the secret cannot forge a consistent chain.
    |
    | Generate one with:  openssl rand -base64 32
    |
    */
    'log_secret' => env('DATA_RETENTION_LOG_SECRET', ''),

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

    /*
    |--------------------------------------------------------------------------
    | Anonymization Placeholders
    |--------------------------------------------------------------------------
    |
    | Default placeholder values used by PlaceholderStrategy when no explicit
    | value is configured on a field.
    |
    */
    'placeholders' => [
        'string' => '[REDACTED]',
        'email' => 'redacted@example.invalid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Logging
    |--------------------------------------------------------------------------
    |
    | Emit additional logger() output during retention runs. Useful while
    | rolling out the package; recommended off in production.
    |
    */
    'debug' => env('DATA_RETENTION_DEBUG', false),
];
