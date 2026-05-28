<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Ginkelsoft\DataRetention\Support\ConsentStatus;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records consent events (grants and withdrawals) under GDPR art. 6(1)(a)
 * and art. 7. Every event becomes one append-only row in `consent_log`
 * with a SHA-256 hash chain linking it to the previous row.
 *
 * Grants and withdrawals are simply two values of the `action` column.
 * Active consent for a (subject, purpose, version) is the latest event
 * being `granted`; absence of an event, or a `withdrawn` event, both
 * mean consent is not active.
 *
 * The action does not enforce idempotency: calling `grant` twice in a
 * row for the same (subject, purpose, version) will produce two
 * `granted` rows. That is on purpose — the second event records a fresh
 * affirmation, which is sometimes exactly what an audit demands. If you
 * want "grant only when not currently granted", check the status first
 * via {@see ConsentStatus}.
 */
final class RecordConsent
{
    /**
     * Record a 'granted' event.
     *
     * @param  string  $subjectId  Identifier of the consenting subject.
     * @param  string  $purpose  What they are consenting to.
     * @param  string  $version  Version of the consent text or
     *                           processing context (defaults to '1').
     * @param  string|null  $source  Where the event came from.
     * @param  array<string, mixed>|null  $metadata  Optional context.
     * @param  Carbon|null  $occurredAt  When the subject acted (defaults
     *                                   to now()). Lets you backfill
     *                                   events captured outside the app.
     */
    public function grant(
        string $subjectId,
        string $purpose,
        string $version = '1',
        ?string $source = null,
        ?array $metadata = null,
        ?Carbon $occurredAt = null,
    ): ConsentEntry {
        return $this->record('granted', $subjectId, $purpose, $version, $source, $metadata, $occurredAt);
    }

    /**
     * Record a 'withdrawn' event.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function withdraw(
        string $subjectId,
        string $purpose,
        string $version = '1',
        ?string $source = null,
        ?array $metadata = null,
        ?Carbon $occurredAt = null,
    ): ConsentEntry {
        return $this->record('withdrawn', $subjectId, $purpose, $version, $source, $metadata, $occurredAt);
    }

    /**
     * Append a single consent event, chaining its hash to the previous row.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private function record(
        string $action,
        string $subjectId,
        string $purpose,
        string $version,
        ?string $source,
        ?array $metadata,
        ?Carbon $occurredAt,
    ): ConsentEntry {
        if ($subjectId === '') {
            throw new \InvalidArgumentException('Subject identifier must not be empty.');
        }
        if ($purpose === '') {
            throw new \InvalidArgumentException('Consent purpose must not be empty.');
        }

        $occurredAt ??= Carbon::now();

        /** @var ConsentEntry $entry */
        $entry = DB::transaction(function () use ($action, $subjectId, $purpose, $version, $source, $metadata, $occurredAt): ConsentEntry {
            $previous = ConsentEntry::query()->orderByDesc('id')->lockForUpdate()->first();
            $previousHash = $previous instanceof ConsentEntry ? $previous->hash : '';

            $occurredAtString = $occurredAt->utc()->format('Y-m-d H:i:s');

            $metadataString = $metadata === null ? null : json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($metadata !== null && $metadataString === false) {
                throw new \RuntimeException('Failed to serialize consent metadata as JSON.');
            }

            $payload = [
                'subject_id' => $subjectId,
                'purpose' => $purpose,
                'version' => $version,
                'action' => $action,
                'source' => $source,
                'metadata' => $metadataString,
                'occurred_at' => $occurredAtString,
            ];

            $secret = config('data-retention.log_secret');
            $hash = HashChain::compute(
                $payload,
                $previousHash,
                is_string($secret) ? $secret : '',
            );

            /** @var ConsentEntry $created */
            $created = ConsentEntry::query()->create([
                'subject_id' => $subjectId,
                'purpose' => $purpose,
                'version' => $version,
                'action' => $action,
                'source' => $source,
                'metadata' => $metadata,
                'occurred_at' => $occurredAt,
                'previous_hash' => $previousHash,
                'hash' => $hash,
            ]);

            return $created;
        });

        return $entry;
    }
}
