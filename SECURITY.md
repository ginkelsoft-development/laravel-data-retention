# Security Policy

This package enforces a privacy-critical control: it deletes or anonymizes
personal data on a schedule and writes a tamper-evident audit log that
organizations rely on to demonstrate GDPR / AVG storage-limitation compliance.
A vulnerability in the retention pipeline or the audit log is, by definition,
a compliance risk for every downstream user. We take that seriously.

## Reporting a vulnerability

**Do not open a public GitHub issue for a security vulnerability.**

Report it privately to **security@ginkelsoft.com**. Include:

- A description of the issue and its potential impact.
- A minimal proof of concept or reproduction steps.
- The affected versions of the package, PHP, and Laravel.
- Your name and affiliation if you would like credit in the advisory.

You will receive an acknowledgement within **two working days**. If you do not,
please follow up — silence indicates a delivery failure, not lack of interest.

After triage, we aim for:

- A confirmed severity assessment within **5 working days**.
- A patch or mitigation released within **30 calendar days** for high or
  critical issues, sooner for actively exploited ones.

Once a fix is released and reasonable time has passed for downstream users to
upgrade, we publish a GitHub Security Advisory describing the issue and
crediting the reporter (with permission).

## What counts as a security issue here

Yes, please report:

- Any way to bypass `retention:run` so expired data is silently retained.
- Any way to modify, delete, or forge a `RetentionLogEntry` without
  invalidating `HashChain::verify()`.
- Any path that leaks personal data into the `retention_log` table itself
  (model field values, anonymized originals, etc).
- Any cross-tenant data exposure introduced by this package.
- Any way to coerce `ApplyRetention` into running with the wrong policy
  (e.g. delete instead of anonymize, or against a different model).
- Hardcoded secrets, weak hashing (anything weaker than SHA-256 with a
  per-installation secret), or default configurations that compromise the
  tamper-evidence guarantee.

Not security issues (open a normal GitHub issue):

- Documentation errors or typos, including in this file.
- Suggestions to add new strategies, scopes, or compliance modules.
- General feature requests.

## Supported versions

Security fixes are issued for the latest stable major version. Earlier majors
receive fixes only for actively exploited critical issues. If you are running
an older major version, upgrading is the recommended remediation.

## Out of scope

- Vulnerabilities in Laravel itself, PHP, the database driver, or other
  upstream dependencies. Report those to the upstream project; we will pull
  in patched versions when they are released.
- Misconfigurations of the host application that this package cannot detect
  (e.g. running the audit log in a database the attacker already controls).

## Coordinated disclosure

If you want to publish your own write-up, we ask that you coordinate the
publication date with us so users have time to upgrade. This is not a legal
requirement, just a courtesy that benefits everyone running this package in
production.
