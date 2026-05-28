# Contributing

Thanks for considering a contribution. This package powers GDPR / AVG
storage-limitation enforcement on production systems, so the bar for changes is
a little higher than for a typical Laravel utility. Read this file once before
opening a pull request.

## Ground rules

- **Scope first.** This package handles storage limitation (GDPR
  art. 5(1)(e)) only. The other AVG controls live in sibling packages
  (`laravel-data-right-to-be-forgotten`, `laravel-data-subject-access`,
  `laravel-data-consent`, `laravel-data-breach-registry`); shared
  primitives live in `laravel-compliance-core`. Pull requests that
  broaden the scope of this specific package beyond storage limitation
  will be closed politely — they belong in the relevant sibling.
- **Compliance is a design constraint, not a feature.** Every change must keep
  the audit log tamper-evident and free of personal data. If your patch could
  break either invariant, it needs a Pest test that proves it does not.
- **No silent behavior changes.** A bug fix that flips an existing behavior
  (e.g. how soft-deleted rows are treated) is a breaking change, and needs a
  CHANGELOG note plus a major version bump.

## Development setup

```bash
git clone https://github.com/ginkelsoft-development/laravel-data-retention.git
cd laravel-data-retention
composer install
```

The package uses Pest 3 with Orchestra Testbench. SQLite in-memory is
configured automatically — no database setup required.

## Required local checks

Before opening a PR, all three of these must pass locally:

```bash
vendor/bin/pest
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

CI runs the same three commands across the full PHP × Laravel matrix
(PHP 8.2-8.5 × Laravel 10-13, 11 combinations). A red CI is a blocker.

## Coding standards

- **Strict types everywhere.** Every `.php` file under `src/` starts with
  `declare(strict_types=1);`.
- **Pint enforces formatting.** Don't argue with Pint; run it and commit the
  result.
- **PHPStan level max.** New code must analyse without lowering the level or
  adding `@phpstan-ignore` comments. If PHPStan complains, fix the underlying
  type — don't paper over it.
- **English only.** All code, docblocks, and commit messages are English.
  Only user-facing strings may be in another language; this package has none.
- **Docblocks on every public method.** Explain *why*, not *what* the code
  does. The reader can see what.

## Tests

- Use Pest. Place feature tests under `tests/Feature/` and unit tests under
  `tests/Unit/`.
- For each new behavior, write at least one test that **fails before** your
  patch and passes after. Reviewers will check this.
- For audit-log-touching changes, add at least one test that calls
  `HashChain::verify()` on the resulting log and asserts it still verifies.
- Use the demo factories (`Client::factory()`, `AuditEntry::factory()`) where
  possible; do not invent new fixture data unless you genuinely need it.

## Commit messages

Conventional Commits, English, present tense imperative:

- `feat: …` for new features
- `fix: …` for bug fixes
- `refactor: …` for non-behavior-changing internal changes
- `test: …` for test-only changes
- `docs: …` for documentation
- `chore: …` for maintenance / dependencies / CI

A commit message should explain **why** the change is being made, not just
restate what the diff shows.

## Pull requests

Open PRs against the `development` branch, not `main`. Include:

1. A clear summary of the change.
2. A reference to the issue you're solving, if one exists.
3. The CHANGELOG entry under `## [Unreleased]`.
4. Confirmation that `pest`, `phpstan`, and `pint --test` are all green
   locally.

## Security issues

Do **not** open a public issue for a security vulnerability. See
[SECURITY.md](SECURITY.md) for the private reporting channel.
