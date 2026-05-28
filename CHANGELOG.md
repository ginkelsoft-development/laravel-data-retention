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
- Demo factories for the forget flow (`ForgetUserFactory`,
  `ForgetProfileFactory`, `ForgetOrderFactory`, `ForgetTicketFactory`)
  with helper states (`withId`, `forSubject`, `reportedBy`,
  `assignedTo`), plus a `ForgettableDemoSeeder` that seeds three
  subjects across all four models — one full subject, one untouched
  subject to prove non-overreach, and one with only partial coverage.
- Demo factory for the subject access flow (`ExportLoginFactory` plus
  reuse of the existing `ForgetUserFactory` / `ForgetProfileFactory`),
  and a real-world test scenario that exercises a single model
  carrying all three policies (HasRetention, Forgettable, Exportable).
- **Consent registry (GDPR art. 6(1)(a) + art. 7)**:
  - `consent_log` table with append-only `ConsentEntry` model. The
    table stores the subject identifier directly because art. 7
    accountability requires "proof that this person consented",
    which an irreversible hash cannot provide. Documented as a
    deliberate trade-off in the README.
  - `Actions\RecordConsent::grant()` and `withdraw()` record events
    with optional `version` (consent text version), `source`
    (web / api / paper / phone / ...), and arbitrary JSON
    `metadata`. Backfills supported via an `occurredAt` parameter.
  - `Support\ConsentStatus` helper: `isGranted()`, `latest()`,
    `history()`, `activeFor()`. Active consent is defined as
    "latest event for (subject, purpose, version) has
    action = granted".
  - CLI: `retention:consent:grant`, `retention:consent:withdraw`,
    `retention:consent:status`. `--consent-version` rather than
    `--version` because Symfony already reserves the latter.
  - Hash-chained over the consent_log so withdrawals cannot be
    silently disappeared from the trail.
- **Breach registry (GDPR art. 33-34)**:
  - Two-table design: `breach_register` (mutable, current state)
    and `breach_event_log` (append-only, hash-chained). Together
    they answer "where do we stand?" and "how did we get here?".
  - `Actions\BreachRegistry`: `register()`, `update()` (with field
    diff in the event log), `reportToAuthority()`,
    `reportToSubjects()`, `contain()`, `resolve()`. Every method is
    atomic: it updates the register row AND appends an event in
    the same DB transaction.
  - `Models\BreachRegisterEntry` exposes
    `authorityNotificationDeadline()`,
    `isReportedToAuthority()`, and
    `isAuthorityNotificationOverdue()` for direct use in
    dashboards.
  - `Support\BreachDeadlines` with `overdue()` and `approaching()`
    queries for the 72-hour clock (art. 33(1)).
  - CLI: `retention:breach:register`, `retention:breach:list`,
    `retention:breach:show`, `retention:breach:deadlines`. The
    deadlines command exits with a non-zero status when there are
    overdue breaches — suitable for a scheduled alerting job.
  - Severities: `low`, `medium`, `high`, `critical` (validated).
    Data categories travel as a JSON array.
- 167 Pest tests across unit and feature suites, including explicit
  tamper-detection scenarios (modify / insert / drop / wrong secret),
  end-to-end chain verification on factory-driven datasets, full
  coverage of the forgotten flow (per-model dispatch, dry-run,
  idempotency, PII non-leakage, soft-delete handling, custom
  `forSubjectQuery` overrides), full coverage of the subject
  access flow (correct field selection, opt-in only, transforms,
  no over-reach, no mutation, log row per matched model with hash
  chain still verifiable), full coverage of consent (grant /
  withdrawal / version scoping / activeFor / hash chain / tamper
  detection / CLI), and full coverage of the breach registry
  (registration / diff updates / no-op updates / reporting /
  status transitions / hash chain / tamper detection / overdue
  detection / approaching window / no PII in log).
- GitHub Actions matrix: PHP 8.2-8.5 × Laravel 10-13 (11 valid combinations),
  with separate PHPStan-max and Pint code-style jobs.

### Changed

- Extracted strategy resolution from `ApplyRetention` into a shared
  `Strategies\StrategyResolver` so both `ApplyRetention` and `ApplyForget`
  use the same logic. No behaviour change.

### Notes

- This package now covers five AVG-controls in one dependency:
  storage limitation (art. 5(1)(e)), right to be forgotten (art. 17),
  right of access (art. 15 + art. 20 portability), consent registry
  (art. 6(1)(a) + art. 7), and breach registry (art. 33-34).
- Identity verification of a subject behind a forget / access /
  consent request is intentionally out of scope — application
  responsibility. The actual notification mechanism for breaches
  (email to the AP, email to affected subjects) is likewise outside
  this package's scope; the registry records the moment you
  completed it.
- `retention_log.model_id` is overloaded: it carries a record primary
  key for retention / forget rows, and a `SubjectHash` for subject
  access rows. Filter by `retention_field` (`subject_access` or
  other) to distinguish them at query time.
- `consent_log` is the only audit table that stores the subject
  identifier directly. This is necessary for art. 7 accountability
  (you must be able to demonstrate WHO consented). Document the
  table in your DPIA and apply your own retention policy.
- PHP 8.0 and 8.1 are intentionally not supported: both have reached
  end-of-life and the modern Pest / PHPUnit toolchain requires PHP 8.2+.

[Unreleased]: https://github.com/ginkelsoft-development/laravel-data-retention/commits/development
