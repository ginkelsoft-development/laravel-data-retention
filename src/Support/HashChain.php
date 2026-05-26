<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Ginkelsoft\DataRetention\Actions\ApplyRetention;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;

/**
 * Deterministic SHA-256 hash chain used by the retention audit log.
 *
 * Every log entry contains a `hash` that is computed from its own
 * normalized payload, the `hash` of the previous entry, and a secret
 * value held in configuration. The result is an append-only log in
 * which any retroactive edit invalidates every following hash.
 *
 * This class is intentionally tiny and pure. It does not touch the
 * database; that wiring lives in the {@see RetentionLogEntry}
 * model and the {@see ApplyRetention} action.
 */
final class HashChain
{
    /**
     * Compute the chained hash for a single log entry.
     *
     * The payload is normalized before hashing so that two identical
     * logical entries always produce the same hash, regardless of
     * insertion order of associative keys. Timestamps are normalized
     * to ISO-8601 strings before hashing.
     *
     * @param  array<string, scalar|\DateTimeInterface|null>  $payload
     *                                                                  The non-hash content of the log entry (model_type, model_id,
     *                                                                  action, retention_period, retention_field, expired_at,
     *                                                                  performed_at).
     * @param  string  $previousHash
     *                                SHA-256 hex of the previous entry, or an empty string for
     *                                the genesis row.
     * @param  string  $secret
     *                          Application secret. If empty the chain is still tamper-
     *                          evident against external attackers without DB access, but
     *                          anyone with write access could forge a consistent chain.
     * @return string 64-character hex SHA-256 hash.
     */
    public static function compute(array $payload, string $previousHash, string $secret): string
    {
        $normalized = self::normalize($payload);
        $serialized = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($serialized === false) {
            throw new \RuntimeException('Failed to serialize retention log payload for hashing.');
        }

        return hash('sha256', $previousHash.'|'.$serialized.'|'.$secret);
    }

    /**
     * Verify a full chain in order.
     *
     * Each entry must be an associative array containing every field
     * that was part of the original payload, plus `previous_hash` and
     * `hash`. The function returns true only if every entry hashes
     * back to its stored `hash` AND chains back to the previous one.
     *
     * @param  iterable<int, array<string, mixed>>  $entries
     */
    public static function verify(iterable $entries, string $secret): bool
    {
        $previousHash = '';

        foreach ($entries as $entry) {
            if (! isset($entry['hash'], $entry['previous_hash'])) {
                return false;
            }

            $entryHash = is_string($entry['hash']) ? $entry['hash'] : '';
            $entryPreviousHash = is_string($entry['previous_hash']) ? $entry['previous_hash'] : '';

            if ($entryPreviousHash !== $previousHash) {
                return false;
            }

            $payload = $entry;
            unset(
                $payload['hash'],
                $payload['previous_hash'],
                $payload['id'],
                $payload['created_at'],
                $payload['updated_at'],
            );

            /** @var array<string, scalar|\DateTimeInterface|null> $payload */
            $expected = self::compute($payload, $previousHash, $secret);

            if (! hash_equals($expected, $entryHash)) {
                return false;
            }

            $previousHash = $entryHash;
        }

        return true;
    }

    /**
     * Normalize a payload so its hash is order-independent and
     * insensitive to PHP's DateTime serialization quirks.
     *
     * @param  array<string, scalar|\DateTimeInterface|null>  $payload
     * @return array<string, string|int|float|bool|null>
     */
    private static function normalize(array $payload): array
    {
        ksort($payload);

        $out = [];
        foreach ($payload as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format(\DateTimeInterface::ATOM);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
