# Ginkelsoft Laravel Data Retention

[![Tests](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/ginkelsoft/laravel-data-retention.svg?style=flat-square)](https://packagist.org/packages/ginkelsoft/laravel-data-retention)
[![License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-10--13-brightgreen?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%20--%208.5-blue?style=flat-square&logo=php)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen?style=flat-square)](phpstan.neon.dist)

## Overview

The GDPR — and its Dutch implementation, the AVG — requires that personal data be kept for no longer than is necessary for the purposes for which it is processed (art. 5(1)(e), the **storage-limitation principle**). Knowing this rule is one thing; **proving** that it is being applied consistently across an Eloquent codebase is another. Most existing tools either silently delete data with no audit trail, or rely on developers remembering to write the right cron job for every new model.

**Laravel Data Retention** fixes that. It lets you declare a retention policy on every Eloquent model, sweeps expired records on a schedule, and writes a **tamper-evident audit log** that lets you demonstrate, after the fact, that the data really was retired — when, how, and under which policy.

This is the **first** module of the GinkelSoft AVG-compliance family. Future packages (consent, subject-access requests, right-to-be-forgotten, breach registry) will share the same conventions and audit-log structure.

---

## Problem Statement

The storage-limitation principle creates three obligations:

1. Have a policy per category of personal data.
2. Apply it automatically and consistently.
3. Be able to **demonstrate** that you did.

Hand-rolled cron scripts solve #2 at best, fail at #3 silently, and tend to drift away from the policy documented in your DPO's spreadsheet. This package addresses all three in one place: policies live next to the model, enforcement is a single command, and proof is automatic.

---

## Key Features

* **Per-model retention policies** declared on the Eloquent model itself, with either a PHP `#[Retention]` attribute or a `$retention` property.
* **Right to be forgotten** (GDPR art. 17): the same delete / anonymize / strategy machinery driven per subject instead of per clock, with its own audit log.
* **Subject access** (GDPR art. 15, doubles as art. 20 portability): collect every record an application holds about a single subject and render it as JSON or Markdown, read-only, with the access itself recorded in the audit chain.
* **Consent registry** (GDPR art. 6(1)(a) + art. 7): append-only event log of every grant and withdrawal of consent, with a status helper to query the current state per (subject, purpose, version).
* **Breach registry** (GDPR art. 33-34): mutable register of personal-data breaches paired with a hash-chained event log, 72-hour deadline helper, and CLI for daily monitoring.
* **Delete or anonymize** on expiry or on request — soft-delete-aware so personal data really leaves the database.
* **Pluggable anonymize strategies**: `null`, `hash`, `placeholder`, or any closure / callable you supply.
* **Tamper-evident audit logs** across all five controls — every log row depends on the previous one and an application secret, verifiable via `HashChain::verify()`.
* **Pluggable export formats** through the `Exporter` contract — JSON and Markdown out of the box, extensible without modifying the package.
* **Dry-run mode** on the destructive commands so you can preview a sweep before trusting it.
* **Chunked, queue-friendly Artisan commands** designed to be scheduled daily.
* **Privacy by design**: the retention, forget, access, and breach logs never hold field values; the consent log holds the subject identifier (necessary for art. 7) but no further PII.
* **Backend-only**, Laravel-native, MIT-licensed, PHPStan-max, Pint-formatted, Pest-tested.

---

## How It Works

### 1. Declare a policy on the model

Two flavors. Use whichever matches your situation; both share the same resolver.

**Attribute form** — best for "just delete after N years":

```php
use Ginkelsoft\DataRetention\Attributes\Retention;
use Ginkelsoft\DataRetention\Concerns\HasRetention;

#[Retention(period: '2 years', from: 'created_at', action: 'delete')]
class AuditEntry extends Model
{
    use HasRetention;
}
```

**Property form** — required for `anonymize`, because each field gets its own strategy:

```php
use Ginkelsoft\DataRetention\Concerns\HasRetention;

class Client extends Model
{
    use HasRetention;

    protected array $retention = [
        'period'    => '5 years',
        'from'      => 'ended_at',          // any timestamp column on the model
        'action'    => 'anonymize',
        'anonymize' => [
            'first_name' => 'placeholder',  // [REDACTED]
            'last_name'  => 'placeholder',
            'bsn'        => 'hash',         // one-way SHA-256, contextual
            'phone'      => 'null',         // overwrite with NULL
            'notes'      => fn ($value, $field, $model) => 'anon-' . $model->id,
        ],
    ];
}
```

### 2. Register the models

`config/data-retention.php`:

```php
return [
    'models' => [
        \App\Models\AuditEntry::class,
        \App\Models\Client::class,
    ],
    // ...
];
```

Only listed models are touched. Forgetting to list a model is the safe failure mode.

### 3. Schedule the sweep

```php
// app/Console/Kernel.php
$schedule->command('retention:run')->dailyAt('02:00');
```

Or run it manually:

```bash
php artisan retention:run --dry-run     # safe preview
php artisan retention:run               # actually retire data
php artisan retention:run --model="App\\Models\\Client"
php artisan retention:run --chunk=1000
```

### 4. Read the audit log

Every action produces one row in the `retention_log` table:

| model_type           | model_id   | action      | retention_period | retention_field | expired_at          | performed_at        | previous_hash | hash    |
| -------------------- | ---------- | ----------- | ---------------- | --------------- | ------------------- | ------------------- | ------------- | ------- |
| App\Models\Client    | 01HXYZ...  | anonymized  | 5 years          | ended_at        | 2020-05-26 00:00:00 | 2026-05-26 02:00:01 | (prev sha256) | sha256… |

Verify the entire chain at any time:

```php
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Facades\DB;

$entries = DB::table('retention_log')->orderBy('id')->get()
    ->map(fn ($row) => (array) $row)
    ->all();

$intact = HashChain::verify($entries, config('data-retention.log_secret'));
```

If the chain ever fails to verify, **somebody touched the log table**. That is exactly what auditors want to be able to detect.

---

## Security Model

| Threat                                                          | Mitigation                                                                                                                       |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Retroactive edit of a log row                                   | Every row's hash depends on the previous row's hash + a secret. Editing a row invalidates every later row.                       |
| Forged log row inserted by an attacker without the `log_secret` | The forged row cannot produce a hash that chains with both its neighbors.                                                        |
| Application code accidentally mutating the log                  | `RetentionLogEntry` throws on `update()` / `delete()`. Bypassing it via raw queries still breaks the chain.                      |
| Leaking PII via the log itself                                  | Only class + primary key + policy metadata are logged. No field values, ever.                                                    |
| Soft-deleted rows hiding personal data forever                  | `retention:run` calls `forceDelete()` on soft-deleted models so the storage-limitation principle is actually satisfied.          |
| Operator confidence before first run                            | `--dry-run` reports every action that would occur, writes nothing.                                                               |

The `data-retention.log_secret` plays the role of a HMAC key: it lives in `.env`, not the database, so an attacker with read/write access to the DB cannot forge a consistent chain unless they also exfiltrate the secret.

---

## Compliance Notes

* **GDPR art. 5(1)(e) / AVG art. 5 lid 1 sub e** — Storage limitation. This package gives you a documented, automated mechanism to retire data and a tamper-evident record that demonstrates compliance to the supervisory authority.
* **GDPR art. 5(2)** — Accountability. The hash chain is the evidence.
* **GDPR art. 15 / art. 20** — Right of access and data portability. The `retention:export` command produces a structured snapshot of every Exportable record per subject.
* **GDPR art. 6(1)(a) / art. 7** — Lawful basis: consent. The consent log demonstrates *when* and *how* consent was given, and that withdrawal was as straightforward as the original grant.
* **GDPR art. 33 / art. 34** — Notification of personal-data breaches. The breach registry produces the formal register that supervisory authorities can audit, and surfaces breaches approaching the 72-hour deadline.
* **ISO 27001:2022 A.5.34 / A.8.10** — Information deletion. The audit log produces the "records of erasure" that this control requires.
* **NEN 7510 / NEN 7513** (Dutch healthcare) — Append-only logging of data lifecycle events is compatible with NEN 7513 requirements; the log itself stores no patient data.

This package is **not legal advice**. Retention periods must be set by your DPO based on your processing purposes.

---

## Installation

```bash
composer require ginkelsoft/laravel-data-retention
php artisan vendor:publish --tag=data-retention-config
php artisan vendor:publish --tag=data-retention-migrations
php artisan migrate
```

Then add a secret to `.env`:

```env
DATA_RETENTION_LOG_SECRET="$(openssl rand -base64 32)"
```

---

## Configuration Reference

```php
return [
    // Eloquent classes that participate in retention sweeps.
    'models' => [],

    // Application secret mixed into every log-row hash.
    'log_secret' => env('DATA_RETENTION_LOG_SECRET', ''),

    // For SoftDeletes models: should already-soft-deleted rows be swept too?
    'include_soft_deleted' => true,

    // Default chunk size; override per command invocation with --chunk=.
    'chunk_size' => 500,

    // Defaults for the PlaceholderStrategy.
    'placeholders' => [
        'string' => '[REDACTED]',
        'email'  => 'redacted@example.invalid',
    ],

    'debug' => env('DATA_RETENTION_DEBUG', false),
];
```

| Option                  | Default      | Description                                                                                          |
| ----------------------- | ------------ | ---------------------------------------------------------------------------------------------------- |
| `models`                | `[]`         | Classes processed by `retention:run`. Anything not listed is never touched.                          |
| `log_secret`            | `''`         | HMAC-like secret for the audit log. **Set this.**                                                    |
| `include_soft_deleted`  | `true`       | Whether soft-deleted rows also count for retention. Recommended `true` (they still hold PII).        |
| `chunk_size`            | `500`        | Records per chunk when iterating large tables.                                                       |
| `placeholders.string`   | `[REDACTED]` | Default value for `PlaceholderStrategy`.                                                             |

---

## Anonymize Strategies

| Strategy id     | Class                        | Output                                                                  |
| --------------- | ---------------------------- | ----------------------------------------------------------------------- |
| `'null'`        | `NullStrategy`               | Sets the field to `null`.                                               |
| `'hash'`        | `HashStrategy`               | SHA-256 of `{model}|{field}|{value}|{secret}`. Stable across re-runs.   |
| `'placeholder'` | `PlaceholderStrategy`        | The configured placeholder string (`[REDACTED]` by default).            |
| `Closure`       | (resolved inline)            | `function (mixed $value, string $field, Model $model): mixed`           |

Implement `Ginkelsoft\DataRetention\Contracts\AnonymizeStrategy` if you need a reusable custom strategy.

---

## Trying It Out (Demo Seeders)

Two factory-driven seeders ship with the package so you can see each control in action against realistic dummy data.

### Time-driven retention

```bash
php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\RetentionDemoSeeder"
php artisan retention:run --dry-run
php artisan retention:run
```

After the run:

- 10 of the 20 audit entries are deleted (the expired ones).
- 5 of the 15 clients are anonymized in place.
- 15 audit-log rows are written, chained, and verifiable.

The factories (`Ginkelsoft\DataRetention\Database\Factories\{ClientFactory,AuditEntryFactory}`) expose `expired()`, `active()`, and `recentlyEnded()` states you can lift into your own test suite as a template for writing factories on your real models.

### Subject-driven forget

```bash
php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\ForgettableDemoSeeder"
php artisan retention:forget alice-01 --dry-run
php artisan retention:forget alice-01
```

The seeder creates three subjects across four related models:

- `alice-01` — a complete record: own user row, profile, two orders, and two tickets (one reported, one assigned).
- `bob-02` — same shape, exists to prove the sweep does not over-reach across subjects.
- `carol-03` — has only a user row and three orders, no profile or ticket.

After forgetting `alice-01`:

- Her user row is deleted, her orders are deleted, her tickets are deleted.
- Her profile is anonymized in place (`first_name` and `last_name` become `[REDACTED]`, `email` becomes a 64-character SHA-256 hash).
- Bob and Carol are untouched.
- Six rows land in `forget_log`, all bound to the same `subject_hash`, chained, and verifiable.

The factories (`Ginkelsoft\DataRetention\Database\Factories\{ForgetUserFactory,ForgetProfileFactory,ForgetOrderFactory,ForgetTicketFactory}`) expose `withId(...)`, `forSubject(...)`, `reportedBy(...)`, and `assignedTo(...)` helper states so you can build similar fixtures for your own models.

### Consent log

```bash
php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\ConsentDemoSeeder"
php artisan retention:consent:status alice-01
php artisan retention:consent:status dan-04
```

The seeder produces five subjects across realistic histories: a single grant, multiple purposes, a withdrawal, a version bump after the consent text changed, and an opt-in-out-in cycle. The full event sequence is hash-chained and verifiable through `HashChain::verify()` against `consent_log`.

`ConsentEntryFactory` is also available with `granted()`, `withdrawn()`, `forSubject()`, `forPurpose()`, `version()`, `via()`, and `at()` helper states. Use `createOneAtATime($count)` instead of `count($n)->create()` when you need multiple chained rows in a row — Laravel batches `count()->create()` builds in a way that breaks the chain by reading the not-yet-persisted previous row.

### Breach registry

```bash
php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\BreachRegistryDemoSeeder"
php artisan retention:breach:list
php artisan retention:breach:deadlines
php artisan retention:breach:show BREACH-DEMO-004
```

The seeder creates five breaches across the lifecycle: fresh and well within the deadline, approaching the 72-hour mark, overdue (the `retention:breach:deadlines` command exits with non-zero for this one), fully handled and resolved (with the complete event log: registered → updated → reported_authority → reported_subjects → contained → resolved), and a low-severity breach that was contained without escalation.

`BreachRegisterEntryFactory` is available with `severity()`, `withCategories()`, `discoveredAt()`, `overdue()`, `approaching()`, `reportedToAuthority()`, `reportedToSubjects()`, `contained()`, and `resolved()` helper states. Note that the factory writes only to `breach_register` — for scenarios that exercise the matching `breach_event_log`, prefer going through the demo seeder or `BreachRegistry` directly.

---

## Right to be Forgotten

Time-based retention answers "is this data old enough to remove?". GDPR art. 17 ("right to be forgotten") answers a different question: "this specific person has asked me to remove **everything** about them, today." The two controls have the same building blocks (delete vs. anonymize, per-field strategies, append-only audit log) but the trigger is different — one is the clock, the other is the subject themselves.

### Declare which models hold subject data

Mirror of the retention pattern: an attribute for simple cases, a property for anonymize. Models must additionally implement the `Ginkelsoft\DataRetention\Contracts\Forgettable` interface — the trait provides the default implementation, the interface gives the orchestrator the type safety it needs.

```php
use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;

#[Forgettable(column: 'id', action: 'delete')]
class User extends Model implements ForgettableContract
{
    use ForgettableTrait;
}

#[Forgettable(column: 'user_id', action: 'delete')]
class Order extends Model implements ForgettableContract
{
    use ForgettableTrait;
}

class Profile extends Model implements ForgettableContract
{
    use ForgettableTrait;

    protected array $forgettable = [
        'column'    => 'user_id',
        'action'    => 'anonymize',
        'anonymize' => [
            'first_name' => 'placeholder',
            'last_name'  => 'placeholder',
            'email'      => 'hash',
        ],
    ];
}
```

For complex subject mappings (subject can appear in either of two columns, polymorphic relation, etc) override the static `forSubjectQuery` method on the model. See `tests/Models/ForgetTicket.php` for an OR-across-two-columns example.

### Register the models

```php
// config/data-retention.php
'forgettable' => [
    'models' => [
        \App\Models\User::class,
        \App\Models\Profile::class,
        \App\Models\Order::class,
    ],
],
```

### Run the sweep

```bash
php artisan retention:forget 01HXYZ --dry-run
php artisan retention:forget 01HXYZ
```

The first argument is the subject identifier: whatever string consistently identifies the person across your models (typically a user primary key or ULID). The orchestrator iterates every registered model and applies its policy to records linked to that subject. Idempotent: a second run finds no new records and writes no new log entries.

### A separate, parallel audit log

Forget actions are recorded in a separate `forget_log` table, not in `retention_log`. This is a deliberate choice. The retention chain is computed over a fixed payload schema; adding a `subject_hash` column there would change the hash of every row written after the migration, breaking verifiability of pre-existing entries. By keeping the two logs apart, each chain stays internally consistent and independently auditable.

The `forget_log` rows contain `subject_hash` (an irreversible SHA-256 of the subject identifier plus `log_secret`), the source model class and primary key, the action (`forgotten_deleted` or `forgotten_anonymized`), timestamps, and the chain hashes. No subject identifier, no field values — just the proof that the person was forgotten.

Verifying the chain works the same way:

```php
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Facades\DB;

$entries = DB::table('forget_log')->orderBy('id')->get()
    ->map(fn ($row) => (array) $row)->all();

$intact = HashChain::verify($entries, config('data-retention.log_secret'));
```

### What the forget sweep does not do

- It does not cascade implicitly. Only models explicitly listed in `forgettable.models` and using the trait + contract are touched. If `Order` is forgotten but `OrderLine` is not in the list, the order lines remain — give them their own Forgettable policy if they hold personal data.
- It does not handle backup or warehouse copies. Those need a separate procedure documented in your DPIA.
- It does not block re-creation. If your application re-fills a profile for the same subject after a forget, that is application logic to fix, not retention logic.

---

## Subject Access (Inzageverzoek)

The third control in this package is read-only: collect every piece of personal data the application holds about one subject and hand it over. This is GDPR art. 15 (right of access) and, when handed over in JSON, doubles as the art. 20 data-portability format. No data is changed or removed — that is what the retention and forget controls are for.

### Declare which fields are part of the export

Mirror of the other two patterns: an attribute for the subject column, a property for the explicit list of fields. Models implement the `Ginkelsoft\DataRetention\Contracts\Exportable` interface and use the corresponding trait. **The field list is opt-in per field**: auto-including every column is unsafe (internal flags, technical foreign keys, hashed values) so this package refuses to do it.

```php
use Ginkelsoft\DataRetention\Attributes\Exportable;
use Ginkelsoft\DataRetention\Concerns\Exportable as ExportableTrait;
use Ginkelsoft\DataRetention\Contracts\Exportable as ExportableContract;

#[Exportable(column: 'id')]
class User extends Model implements ExportableContract
{
    use ExportableTrait;

    protected array $exportable = [
        'fields' => [
            'id'    => 'Subject identifier',
            'email' => 'E-mailadres',
        ],
    ];
}

class Profile extends Model implements ExportableContract
{
    use ExportableTrait;

    protected array $exportable = [
        'column' => 'user_id',
        'fields' => [
            'first_name'    => 'Voornaam',
            'last_name'     => 'Achternaam',
            'email'         => ['label' => 'E-mailadres'],
            'logged_in_at'  => [
                'label'     => 'Aangemeld op',
                'transform' => fn ($v) => $v?->format('Y-m-d H:i:s'),
            ],
            // internal_note is intentionally not listed: it stays out of the export.
        ],
    ];
}
```

A model can carry **all three** policies at once (`HasRetention`, `Forgettable`, `Exportable`). When both `Forgettable` and `Exportable` are used together, PHP requires explicit trait conflict resolution because both define `forSubjectQuery`. The default implementations are functionally identical when both policies use the same subject column (the common case), so picking one with `insteadof` is enough:

```php
use Ginkelsoft\DataRetention\Concerns\Exportable;
use Ginkelsoft\DataRetention\Concerns\Forgettable;

class User extends Model implements ExportableContract, ForgettableContract
{
    use Exportable, Forgettable {
        Forgettable::forSubjectQuery insteadof Exportable;
    }
}
```

If the two policies need different columns, override `forSubjectQuery` on the model itself instead of using `insteadof`.

### Register the models

```php
// config/data-retention.php
'exportable' => [
    'models' => [
        \App\Models\User::class,
        \App\Models\Profile::class,
    ],
],
```

### Run the export

```bash
php artisan retention:export 01HXYZ
php artisan retention:export 01HXYZ --format=markdown
php artisan retention:export 01HXYZ --format=json --output=storage/exports/01HXYZ.json
```

Without `--output` the export is written to STDOUT, so it can be piped or captured. With `--output` it lands in the given file (missing intermediate directories are created). Two formats ship by default; the `Ginkelsoft\DataRetention\Contracts\Exporter` interface lets you add more (HTML, CSV, PDF) without touching the rest of the package.

### Accountability without leaking data

Every subject access is itself a verwerking, so we log it. One row lands in `retention_log` per model the subject had data in, with:

- `action` = `subject_access_exported`
- `retention_field` = the sentinel `subject_access` (so it filters cleanly)
- `model_type` = the FQCN of the matched model
- `model_id` = the irreversible `SubjectHash` of the subject (NOT the subject identifier itself)
- `retention_period` = the matched record count (e.g. `3 records`)

The retention_log hash chain stays intact: every access row links to the previous row in the chain, so the same `HashChain::verify()` call demonstrates that the access trail is untampered.

### Gotchas specific to subject access

- **Identity verification is your problem, not ours.** This package does not check whether the requester is actually the subject. That is application-level concern — typically a verified email round-trip, an authenticated session, or a manual KYC step before you call the command. Running `retention:export` against an unverified identifier is a data breach waiting to happen.
- **`retention_log.model_id` carries two semantics.** For time-driven retention and right-to-be-forgotten it is the primary key of the source record. For subject access it is a SubjectHash. The `retention_field` value distinguishes them at query time. If you build dashboards on top of the log, filter by `retention_field`.
- **The export is a snapshot.** Records created or modified after the export are obviously not in it. If the subject asks for a fresh export tomorrow, run it again — accountability comes from the per-call log row.
- **PDF is intentionally not built-in.** Adding a PDF generator would pull in a heavy dependency for what is essentially an Exporter contract that you can implement in a project-specific way (Dompdf, mPDF, Browsershot). The JSON and Markdown defaults cover the common cases without weighing the package down.

---

## Consent (Toestemming)

GDPR art. 6(1)(a) lets you process personal data when the subject has consented; art. 7 then requires that you can demonstrate consent was given. This module is the demonstration: every grant and every withdrawal is recorded as an append-only, hash-chained event in `consent_log`. Whether consent is currently active for a given (subject, purpose) is derived from the latest event for that pair.

Unlike the other audit logs in this package, `consent_log` does store the subject identifier directly. Consent inherently requires identification — you cannot prove "this person consented" without knowing who they are. Document that in your DPIA and apply your own retention policy to this table.

### Record a grant or withdrawal

```php
use Ginkelsoft\DataRetention\Actions\RecordConsent;

$consent = new RecordConsent;

$consent->grant(
    subjectId: '01HXYZ',
    purpose: 'newsletter',
    version: '2026-05',
    source: 'web',
    metadata: ['ip' => '203.0.113.5', 'form' => 'signup-v3'],
);

$consent->withdraw(
    subjectId: '01HXYZ',
    purpose: 'newsletter',
    version: '2026-05',
    source: 'email',
);
```

`version` lets you tie consent to a specific consent text or processing context. When you change your terms, prior consent does not automatically cover the new version — record a fresh grant against the new version string.

### Query consent

```php
use Ginkelsoft\DataRetention\Support\ConsentStatus;

$status = new ConsentStatus;

$status->isGranted('01HXYZ', 'newsletter');                 // true / false
$status->isGranted('01HXYZ', 'newsletter', version: '2026-05');
$status->latest('01HXYZ', 'newsletter');                    // most recent ConsentEntry or null
$status->activeFor('01HXYZ');                                // map of purpose => ConsentEntry
$status->history('01HXYZ', purpose: 'newsletter');           // Collection<ConsentEntry>
```

`activeFor` returns only purposes whose latest event is `granted` — perfect for an account dashboard that lists "what you currently consent to".

### CLI

For ops, backfills, and tests:

```bash
php artisan retention:consent:grant 01HXYZ newsletter --consent-version=2026-05 --source=web
php artisan retention:consent:withdraw 01HXYZ newsletter --consent-version=2026-05 --source=email
php artisan retention:consent:status 01HXYZ
```

`--consent-version` rather than `--version` because Symfony already uses `--version` as a reserved option.

### Gotchas specific to consent

- **The log stores subject identifiers directly.** That is necessary for art. 7 accountability — proof of consent has to be linkable to a real person. Mention this table in your DPIA.
- **No automatic deduplication.** Two `grant` calls in a row produce two `granted` rows. Sometimes that is exactly what you want (re-affirming consent). When you do want "only when not currently granted" semantics, check `ConsentStatus::isGranted()` first.
- **Withdrawal does not delete the prior grant.** It records a new event that supersedes it. The grant stays in the log forever — that is what makes the chain a usable audit trail.
- **Forget does not automatically cascade to consent_log.** A subject exercising their right to be forgotten will not have their consent records removed unless you explicitly register `ConsentEntry` as Forgettable. The legal argument for keeping consent + withdrawal records even after forget is real (you may need them to defend the lawfulness of past processing), so this is opt-in rather than default.

---

## Datalek-registratie (Breach Registry)

GDPR art. 33-34 require you to keep a register of every personal-data breach and, for serious ones, to notify the supervisory authority within 72 hours of discovery and (when the risk is high) the affected subjects as well. This module is that register.

Two tables work together. `breach_register` holds the current state of each breach — open, contained, resolved, reported to whom and when. `breach_event_log` is the append-only, hash-chained audit trail of every state transition. The register answers "where do we stand?"; the event log answers "how did we get here?", and is the part an auditor will scrutinize.

The event log holds only metadata: action names, field diffs, optionally an actor. It never holds personal data — that data lives in the source systems the breach concerns, not in the register.

### Register a breach

```php
use Ginkelsoft\DataRetention\Actions\BreachRegistry;
use Illuminate\Support\Carbon;

$registry = new BreachRegistry;

$breach = $registry->register(
    reference: 'BREACH-2026-001',
    discoveredAt: Carbon::parse('2026-05-27 09:15'),
    description: 'Misdirected client export sent to wrong recipient.',
    severity: 'high',
    occurredAt: Carbon::parse('2026-05-27 08:50'),
    dataCategories: ['name', 'email', 'order_history'],
    subjectsAffected: 42,
    cause: 'Operator selected the wrong recipient group.',
    actor: 'ops@example.com',
);
```

The 72-hour deadline for notifying the supervisory authority (AP in NL) runs from `discoveredAt`. The model exposes `authorityNotificationDeadline()` and `isAuthorityNotificationOverdue()` for direct use in dashboards.

### Update, contain, resolve

```php
$registry->update('BREACH-2026-001', [
    'mitigation' => 'Recipient confirmed deletion. Tokens revoked.',
    'severity'   => 'medium',
], actor: 'ops@example.com');

$registry->reportToAuthority('BREACH-2026-001', notificationReference: 'AP-2026-9999');
$registry->reportToSubjects('BREACH-2026-001', channel: 'email');

$registry->contain('BREACH-2026-001');
$registry->resolve('BREACH-2026-001');
```

Each call atomically updates the register row and appends a hash-chained event. Updates with identical values are no-ops — no event is written when nothing actually changes.

### Find the deadlines that matter

```php
use Ginkelsoft\DataRetention\Support\BreachDeadlines;

$deadlines = new BreachDeadlines(warningWindowHours: 24);

$overdue = $deadlines->overdue();         // 72 hours passed, authority not notified
$approaching = $deadlines->approaching(); // deadline in the next 24 hours
```

### CLI

```bash
php artisan retention:breach:register BREACH-2026-001 \
    --description="Misdirected export" \
    --severity=high \
    --discovered="2026-05-27 09:15" \
    --subjects=42 \
    --categories="name,email"

php artisan retention:breach:list
php artisan retention:breach:list --status=open

php artisan retention:breach:show BREACH-2026-001

php artisan retention:breach:deadlines
php artisan retention:breach:deadlines --warning=48
```

`retention:breach:deadlines` exits with a non-zero code when there are overdue breaches — perfect for a scheduled job that pages someone when a 72-hour clock is about to expire.

### Gotchas specific to the breach registry

- **No notification is automatic.** This module records that a breach happened and that you notified — it does NOT actually send the email to the AP or to subjects. The notification itself is a business process you own. Use `reportToAuthority` / `reportToSubjects` to mark the moment you completed it.
- **Severity is your judgement.** The package accepts `low`, `medium`, `high`, `critical` as enum-like values, but does not assess them for you. Whether a breach requires subject notification (art. 34: "high risk") is your DPIA call.
- **The register is the canonical record, the event log is the proof.** Direct Eloquent `update()` on `BreachRegisterEntry` is allowed by Laravel but skips the event log; always go through `BreachRegistry` so the audit trail stays complete.

---

## Framework Compatibility

The CI matrix runs every valid PHP × Laravel combination on every push. Combinations that Laravel itself does not support (e.g. PHP 8.2 + Laravel 13, since Laravel 13 requires PHP 8.3+) are omitted on purpose.

| Laravel Version | Supported PHP Versions |
| --------------- | ---------------------- |
| **10.x**        | 8.2 – 8.3              |
| **11.x**        | 8.2 – 8.4              |
| **12.x**        | 8.3 – 8.5              |
| **13.x**        | 8.3 – 8.5              |

> PHP 8.0 and 8.1 are intentionally not supported: both have reached end-of-life and the modern Pest / PHPUnit toolchain requires PHP 8.2+.

### Supported Databases

Database-agnostic — anything Eloquent supports works. Tested on:

| Database            | Status                                |
| ------------------- | ------------------------------------- |
| **MySQL / MariaDB** | Fully supported                       |
| **PostgreSQL**      | Fully supported                       |
| **SQLite**          | Fully supported (used in CI tests)    |
| **SQL Server**      | Supported                             |

---

## Gotchas

These are intentional design choices. Read them once.

* **Relations are not cascaded.** This package only touches the model whose retention policy expired. If you have an `Order hasMany OrderLine` and your policy is on `Order`, the order lines won't disappear. Either give `OrderLine` its own policy or use a database-level `ON DELETE CASCADE` on the foreign key.
* **NULL in the `from` field never expires.** A `Client` with `ended_at = null` is treated as still active. That's a feature, not a bug — but it does mean that mis-typed or never-populated columns will quietly skip retention. Add a database-level `NOT NULL` once the field has a value, or watch for it in code review.
* **Soft-deleted rows are force-deleted on expiry.** If you rely on soft-delete as a "trash can", remember that retention will eventually empty the trash. Set `include_soft_deleted` to `false` only if you have a separate cleanup process for trashed rows.
* **The audit log grows forever.** That's by design; you need it for compliance. Plan to archive `retention_log` rows older than your statutory audit-trail retention (usually 5–7 years in NL) **to cold storage**, not delete them — and verify the hash chain before archiving.
* **Changing `log_secret` invalidates the existing chain.** Rotate it only as part of an explicit audit rotation procedure, with the new chain starting from scratch and the old one signed off by the DPO.
* **`hash` strategy needs a wide column.** The output is 64 hex chars. If a field is `VARCHAR(20)`, the migration to widen it should land before you turn on retention.

---

## Roadmap

This package covers five GDPR controls in a single dependency:

- Storage limitation (art. 5(1)(e))
- Right to be forgotten (art. 17)
- Right of access (art. 15, doubling as art. 20 portability)
- Consent registry (art. 6(1)(a) + art. 7)
- Breach registry (art. 33-34)

What is intentionally NOT in this package:

- Identity verification of the subject behind a forget / access / consent request — application responsibility.
- The notification mechanism itself for breaches (email to AP, email to subjects) — business process you own; the registry records the moment you completed it.
- Generic GDPR consulting. The package gives you the mechanics; the policies, retention periods, severity assessments, and DPIA judgements are yours.

If you have opinions or want to discuss adjacent capabilities, open an issue on GitHub.

---

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

---

## Contributing

Pull requests welcome — read [CONTRIBUTING.md](CONTRIBUTING.md) first. The
scope of this package is intentionally narrow; sibling concerns (consent,
subject-access, right-to-be-forgotten, breach registry) belong in separate
modules, see the Roadmap above.

## Security

Found a vulnerability? **Do not open a public issue.** See
[SECURITY.md](SECURITY.md) for the private reporting channel.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a complete list of changes per release.

---

## License

MIT License — see [LICENSE](LICENSE).
(c) 2026 Ginkelsoft
