# Ginkelsoft Laravel Data Retention

[![Tests](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml/badge.svg)](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/ginkelsoft/laravel-data-retention.svg?style=flat-square)](https://packagist.org/packages/ginkelsoft/laravel-data-retention)
[![License](https://img.shields.io/github/license/ginkelsoft-development/laravel-data-retention.svg?style=flat-square)](LICENSE.md)
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
* **Delete or anonymize** on expiry — soft-delete-aware so personal data really leaves the database.
* **Pluggable anonymize strategies**: `null`, `hash`, `placeholder`, or any closure / callable you supply.
* **Tamper-evident audit log** with a SHA-256 hash chain — every row depends on the previous one and an application secret.
* **Dry-run mode** (`retention:run --dry-run`) so you can preview a sweep before trusting it.
* **Chunked, queue-friendly Artisan command** designed to be scheduled daily.
* **Privacy by design**: the audit log contains pointers (class + primary key), never the original field values.
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

## Trying It Out (Demo Seeder)

A factory + seeder ship with the package's test models so you can see the system in action immediately:

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

This package is the **first** module of a wider GinkelSoft AVG-compliance family. The following packages will share its conventions (config pattern, audit-log structure) but are **not** part of this release:

- `ginkelsoft/laravel-data-consent` — recording and revoking processing consent.
- `ginkelsoft/laravel-data-subject-access` — automated subject-access (inzage) exports.
- `ginkelsoft/laravel-data-right-to-be-forgotten` — provable, complete erasure across an aggregate.
- `ginkelsoft/laravel-data-breach-registry` — datalek registration aligned with AVG art. 33–34.

If you have opinions about the shape of any of these, open an issue.

---

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

---

## License

MIT License — see [LICENSE.md](LICENSE.md).
(c) 2026 Ginkelsoft
