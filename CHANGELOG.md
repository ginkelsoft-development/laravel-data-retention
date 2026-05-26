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
- Demo factories (`ClientFactory`, `AuditEntryFactory`) and a
  `RetentionDemoSeeder` so a developer can see a realistic mix of
  expired/anonymized/deleted records on first install.
- 61 Pest tests across unit and feature suites, including explicit
  tamper-detection scenarios (modify / insert / drop / wrong secret) and
  end-to-end chain verification on factory-driven datasets.
- GitHub Actions matrix: PHP 8.2-8.5 × Laravel 10-13 (11 valid combinations),
  with separate PHPStan-max and Pint code-style jobs.

### Notes

- This package is the **first** module of the GinkelSoft AVG-compliance
  family. Future packages (consent, subject-access, right-to-be-forgotten,
  breach registry) will share its config pattern and audit-log structure.
- PHP 8.0 and 8.1 are intentionally not supported: both have reached
  end-of-life and the modern Pest / PHPUnit toolchain requires PHP 8.2+.

[Unreleased]: https://github.com/ginkelsoft-development/laravel-data-retention/commits/development
