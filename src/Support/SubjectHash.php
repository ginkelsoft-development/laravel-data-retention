<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

/**
 * One-way hash of a subject identifier for use in the forget audit log.
 *
 * The same subject identifier (e.g. a user ID, ULID, or email) always
 * hashes to the same value, allowing the auditor to confirm that
 * "this subject was forgotten" while keeping the identifier itself
 * out of the database.
 *
 * The hash incorporates `config('data-retention.log_secret')`, so the
 * mapping from identifier to hash cannot be reproduced by an attacker
 * who has read access to the audit table but not to the secret.
 */
final class SubjectHash
{
    /**
     * Compute the subject hash.
     *
     * @param  string  $subjectId  Caller-provided identifier (string).
     *                             Caller decides what an identifier is:
     *                             primary key, UUID, ULID, email, etc.
     * @param  string  $secret  `data-retention.log_secret`.
     * @return string 64-character hex SHA-256 digest.
     */
    public static function compute(string $subjectId, string $secret): string
    {
        return hash('sha256', 'subject|'.$subjectId.'|'.$secret);
    }
}
