# Ginkelsoft Laravel Data Retention

[![Tests](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/ginkelsoft-development/laravel-data-retention/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/ginkelsoft/laravel-data-retention.svg?style=flat-square)](https://packagist.org/packages/ginkelsoft/laravel-data-retention)
[![License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-10--13-brightgreen?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%20--%208.5-blue?style=flat-square&logo=php)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen?style=flat-square)](phpstan.neon.dist)

## Overview

The GDPR — and its Dutch implementation, the AVG — requires that personal data
be kept for no longer than is necessary for the purposes for which it is
processed (art. 5(1)(e), the **storage-limitation principle**). Knowing this
rule is one thing; **proving** that it is being applied consistently across an
Eloquent codebase is another.

**Laravel Data Retention** lets you declare a retention policy on every
Eloquent model, sweeps expired records on a schedule, and writes a
**tamper-evident audit log** that lets you demonstrate, after the fact, that
the data really was retired — when, how, and under which policy.

This is the storage-limitation member of the GinkelSoft compliance family.
The other AVG controls (right to be forgotten, subject access, consent,
breach registry) live in sibling packages — install the hub to get the
whole family in one go. See **The family** below.

> **Upgrading from v1.x?** The v1.x release of this package bundled five
> controls in one. v2.x reduces it to storage limitation only; the other
> four are now separate packages. Hash chains in existing
> `retention_log` tables remain byte-identical and continue to verify.
> See [UPGRADE.md](https://github.com/ginkelsoft-development/laravel-compliance-core/blob/development/UPGRADE.md)
> in the core repo for the migration steps.

## The family

| Package | GDPR Article(s) | Role |
| ------- | --------------- | ---- |
| [`laravel-compliance-core`](https://github.com/ginkelsoft-development/laravel-compliance-core) | art. 5(2) | Shared primitives (hash chain, strategies, subject hash) |
| **`laravel-data-retention`** | **art. 5(1)(e)** | **Storage limitation — this package** |
| [`laravel-data-right-to-be-forgotten`](https://github.com/ginkelsoft-development/laravel-data-right-to-be-forgotten) | art. 17 | Subject-driven erasure |
| [`laravel-data-subject-access`](https://github.com/ginkelsoft-development/laravel-data-subject-access) | art. 15 + 20 | Read-only subject export |
| [`laravel-data-consent`](https://github.com/ginkelsoft-development/laravel-data-consent) | art. 6(1)(a) + 7 | Consent registry |
| [`laravel-data-breach-registry`](https://github.com/ginkelsoft-development/laravel-data-breach-registry) | art. 33 + 34 | Personal-data breach register |
| [`laravel-compliance-hub`](https://github.com/ginkelsoft-development/laravel-compliance-hub) | art. 5(2) | Umbrella: installs the whole family, verifies every chain |

## How it works

### 1. Declare a policy on the model

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

**Property form** — required for `anonymize`, because each field gets its own
strategy:

```php
use Ginkelsoft\DataRetention\Concerns\HasRetention;

class Client extends Model
{
    use HasRetention;

    protected array $retention = [
        'period'    => '5 years',
        'from'      => 'ended_at',
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
    'include_soft_deleted' => true,
    'chunk_size' => 500,
];
```

Only listed models are touched. Forgetting to list a model is the safe
failure mode.

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

Verify the entire chain at any time, using the shared core `HashChain`:

```php
use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Illuminate\Support\Facades\DB;

$entries = DB::table('retention_log')->orderBy('id')->get()
    ->map(fn ($row) => (array) $row)
    ->all();

$intact = HashChain::verify($entries, LogSecret::value());
```

If the chain ever fails to verify, **somebody touched the log table**. That
is exactly what auditors want to be able to detect. (Or run
`php artisan compliance:verify` from the
[hub](https://github.com/ginkelsoft-development/laravel-compliance-hub) to
verify every chain in the family in one go.)

## Security model

| Threat                                                          | Mitigation                                                                                                                       |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Retroactive edit of a log row                                   | Every row's hash depends on the previous row's hash + a shared secret. Editing a row invalidates every later row.                |
| Forged log row inserted by an attacker without the `log_secret` | The forged row cannot produce a hash that chains with both its neighbors.                                                        |
| Application code accidentally mutating the log                  | `RetentionLogEntry` throws on `update()` / `delete()`. Bypassing it via raw queries still breaks the chain.                      |
| Leaking PII via the log itself                                  | Only class + primary key + policy metadata are logged. No field values, ever.                                                    |
| Soft-deleted rows hiding personal data forever                  | `retention:run` calls `forceDelete()` on soft-deleted models so the storage-limitation principle is actually satisfied.          |
| Operator confidence before first run                            | `--dry-run` reports every action that would occur, writes nothing.                                                               |

The shared `compliance.log_secret` (with BC fallback to
`data-retention.log_secret`) plays the role of a HMAC key: it lives in `.env`,
not the database, so an attacker with read/write access to the DB cannot
forge a consistent chain unless they also exfiltrate the secret.

## Compliance notes

* **GDPR art. 5(1)(e) / AVG art. 5 lid 1 sub e** — Storage limitation. This
  package gives you a documented, automated mechanism to retire data and a
  tamper-evident record that demonstrates compliance to the supervisory
  authority.
* **GDPR art. 5(2)** — Accountability. The hash chain (provided by core) is
  the evidence.
* **ISO 27001:2022 A.5.34 / A.8.10** — Information deletion. The audit log
  produces the "records of erasure" that this control requires.
* **NEN 7510 / NEN 7513** (Dutch healthcare) — Append-only logging of data
  lifecycle events is compatible with NEN 7513 requirements; the log itself
  stores no patient data.

This package is **not legal advice**. Retention periods must be set by your
DPO based on your processing purposes.

## Installation

```bash
composer require ginkelsoft/laravel-data-retention
php artisan vendor:publish --tag=compliance-config
php artisan vendor:publish --tag=data-retention-config
php artisan vendor:publish --tag=data-retention-migrations
php artisan migrate
```

Then add a secret to `.env`:

```env
COMPLIANCE_LOG_SECRET="$(openssl rand -base64 32)"
```

Existing installations upgrading from v1.x can keep their
`DATA_RETENTION_LOG_SECRET` — core's `LogSecret` helper reads it as a
fallback so existing hash chains keep verifying after the upgrade.

## Configuration reference

| Option                  | Default | Description                                                                                          |
| ----------------------- | ------- | ---------------------------------------------------------------------------------------------------- |
| `models`                | `[]`    | Classes processed by `retention:run`. Anything not listed is never touched.                          |
| `include_soft_deleted`  | `true`  | Whether soft-deleted rows also count for retention. Recommended `true` (they still hold PII).        |
| `chunk_size`            | `500`   | Records per chunk when iterating large tables.                                                       |

The signing secret and anonymize placeholders are in the shared
[`compliance` config](https://github.com/ginkelsoft-development/laravel-compliance-core)
provided by `ginkelsoft/laravel-compliance-core`.

## Anonymize strategies

The three built-in strategies live in
[`laravel-compliance-core`](https://github.com/ginkelsoft-development/laravel-compliance-core)
and are shared with `laravel-data-right-to-be-forgotten`:

| Strategy id     | Class                                                  | Output                                                                  |
| --------------- | ------------------------------------------------------ | ----------------------------------------------------------------------- |
| `'null'`        | `Ginkelsoft\ComplianceCore\Strategies\NullStrategy`    | Sets the field to `null`.                                               |
| `'hash'`        | `Ginkelsoft\ComplianceCore\Strategies\HashStrategy`    | SHA-256 of `{model}|{field}|{value}|{secret}`. Stable across re-runs.   |
| `'placeholder'` | `Ginkelsoft\ComplianceCore\Strategies\PlaceholderStrategy` | The configured placeholder string (`[REDACTED]` by default).        |
| `Closure`       | (resolved inline)                                      | `function (mixed $value, string $field, Model $model): mixed`           |

Implement `Ginkelsoft\ComplianceCore\Contracts\AnonymizeStrategy` if you
need a reusable custom strategy.

## Trying it out (demo seeder)

```bash
php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\RetentionDemoSeeder"
php artisan retention:run --dry-run
php artisan retention:run
```

After the run:

- 10 of the 20 audit entries are deleted (the expired ones).
- 5 of the 15 clients are anonymized in place.
- 15 audit-log rows are written, chained, and verifiable.

## Framework compatibility

| Laravel Version | Supported PHP Versions |
| --------------- | ---------------------- |
| **10.x**        | 8.2 – 8.3              |
| **11.x**        | 8.2 – 8.4              |
| **12.x**        | 8.3 – 8.5              |
| **13.x**        | 8.3 – 8.5              |

### Supported databases

Database-agnostic — anything Eloquent supports works. Tested on MySQL,
MariaDB, PostgreSQL, SQLite (used in CI), SQL Server.

## Gotchas

* **Relations are not cascaded.** This package only touches the model whose
  retention policy expired. Give related models their own policy or use
  database-level `ON DELETE CASCADE`.
* **NULL in the `from` field never expires.** A `Client` with
  `ended_at = null` is treated as still active. Mis-typed or never-populated
  columns will quietly skip retention.
* **Soft-deleted rows are force-deleted on expiry.** Retention will
  eventually empty the trash. Set `include_soft_deleted` to `false` only if
  you have a separate cleanup process for trashed rows.
* **The audit log grows forever.** Plan to archive `retention_log` rows
  older than your statutory audit-trail retention (usually 5–7 years in NL)
  **to cold storage**, not delete them — and verify the hash chain before
  archiving.
* **Changing `log_secret` invalidates the existing chain.** Rotate it only
  as part of an explicit audit rotation procedure.
* **`hash` strategy needs a wide column.** The output is 64 hex chars. If a
  field is `VARCHAR(20)`, the migration to widen it should land before you
  turn on retention.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Contributing

Pull requests welcome — read [CONTRIBUTING.md](CONTRIBUTING.md) first.

## Security

Found a vulnerability? **Do not open a public issue.** See
[SECURITY.md](SECURITY.md) for the private reporting channel.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT License — see [LICENSE](LICENSE).
(c) 2026 Ginkelsoft
