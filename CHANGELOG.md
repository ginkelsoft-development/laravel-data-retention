# Changelog

All notable changes to `ginkelsoft/laravel-data-retention` are documented in this
file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Per-model retention policies via the `#[Retention]` attribute or a
  `$retention` array property on Eloquent models, both surfaced through the
  `HasRetention` trait.
- Two retention actions: `delete` (force-delete on SoftDeletes models so the
  storage-limitation principle is actually satisfied) and `anonymize` with
  pluggable per-field strategies.
- Built-in anonymize strategies: `NullStrategy`, `HashStrategy`,
  `PlaceholderStrategy`. Custom callables (including closures) accepted as
  field strategies.
- `AnonymizeStrategy` contract for application-defined strategies.
- `retention_log` table with an append-only Eloquent model
  (`RetentionLogEntry`) that blocks `update()` / `delete()` via model events.
- `HashChain` support class that produces a tamper-evident SHA-256 chain over
  every log row, bound to `config('data-retention.log_secret')`. Verification
  with `HashChain::verify()` detects retroactive edits, forged inserts, and
  silently-dropped rows.
- `retention:run` Artisan command with `--dry-run`, `--model`, and `--chunk`
  options. Chunked, idempotent, scheduler-friendly.
- **Right to be forgotten (GDPR art. 17)**:
  - `Forgettable` interface (`Contracts\Forgettable`), trait
    (`Concerns\Forgettable`), and `#[Forgettable]` class attribute, with the
    same attribute / property duality as the retention API.
  - `ForgettableConfig` resolver and `SubjectHash` support class producing
    an irreversible SHA-256 of the subject identifier bound to `log_secret`.
  - `ApplyForget` per-record action and `ForgetSubject` orchestrator that
    sweeps every model registered under `data-retention.forgettable.models`.
  - `retention:forget {subject} [--dry-run]` Artisan command.
  - Dedicated `forget_log` table with the same hash-chain structure as
    `retention_log` but kept apart on purpose: extending `retention_log`
    with a `subject_hash` column would have changed the payload schema and
    invalidated the chain of any pre-existing log rows.
  - Idempotent: a second forget request for the same subject finds nothing
    new to act on and writes no additional log entries.
- Demo factories (`ClientFactory`, `AuditEntryFactory`) and a
  `RetentionDemoSeeder` so a developer can see a realistic mix of
  expired/anonymized/deleted records on first install.
- 87 Pest tests across unit and feature suites, including explicit
  tamper-detection scenarios (modify / insert / drop / wrong secret),
  end-to-end chain verification on factory-driven datasets, and full
  coverage of the forgotten flow (per-model dispatch, dry-run, idempotency,
  PII non-leakage, soft-delete handling, custom `forSubjectQuery` overrides).
- GitHub Actions matrix: PHP 8.2-8.5 × Laravel 10-13 (11 valid combinations),
  with separate PHPStan-max and Pint code-style jobs.

### Changed

- Extracted strategy resolution from `ApplyRetention` into a shared
  `Strategies\StrategyResolver` so both `ApplyRetention` and `ApplyForget`
  use the same logic. No behaviour change.

### Notes

- This package now covers both AVG-controls 5(1)(e) and 17. The remaining
  GinkelSoft AVG-compliance modules (consent, subject-access, breach
  registry) are still planned as separate packages and will share this
  package's config pattern, audit-log structure, and hash chain.
- PHP 8.0 and 8.1 are intentionally not supported: both have reached
  end-of-life and the modern Pest / PHPUnit toolchain requires PHP 8.2+.

[Unreleased]: https://github.com/ginkelsoft-development/laravel-data-retention/commits/development
