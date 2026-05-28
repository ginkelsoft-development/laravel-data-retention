<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Ginkelsoft\DataRetention\Models\BreachEventLogEntry;
use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service that owns the breach-register lifecycle (AVG art. 33-34).
 *
 * Every public method does two things atomically: it mutates the
 * `breach_register` row to reflect the new business reality, and it
 * appends a row to `breach_event_log` (with hash chain) so an auditor
 * can later reconstruct the timeline and prove the log has not been
 * tampered with.
 *
 * Action types written to the event log:
 *  - `registered`         — the breach was first recorded
 *  - `updated`            — fields on the register row changed
 *  - `reported_authority` — notified the supervisory authority
 *  - `reported_subjects`  — notified the affected subjects
 *  - `contained`          — status set to 'contained'
 *  - `resolved`           — status set to 'resolved'
 *
 * Privacy: the event log records ONLY metadata — references, action
 * names, field diffs, optionally an actor. It never holds personal
 * data; that data is in the source systems that the breach concerns,
 * not in this register.
 */
final class BreachRegistry
{
    /** @var array<string, bool> */
    private const ALLOWED_SEVERITIES = [
        'low' => true,
        'medium' => true,
        'high' => true,
        'critical' => true,
    ];

    /**
     * Record a new breach in the register and append a `registered`
     * event to the audit log.
     *
     * @param  list<string>|null  $dataCategories
     */
    public function register(
        string $reference,
        Carbon $discoveredAt,
        string $description,
        string $severity = 'medium',
        ?Carbon $occurredAt = null,
        ?array $dataCategories = null,
        int $subjectsAffected = 0,
        ?string $cause = null,
        ?string $actor = null,
    ): BreachRegisterEntry {
        $this->ensureNotEmpty('reference', $reference);
        $this->ensureNotEmpty('description', $description);
        $this->ensureSeverity($severity);

        /** @var BreachRegisterEntry $breach */
        $breach = DB::transaction(function () use (
            $reference,
            $discoveredAt,
            $description,
            $severity,
            $occurredAt,
            $dataCategories,
            $subjectsAffected,
            $cause,
            $actor,
        ): BreachRegisterEntry {
            /** @var BreachRegisterEntry $entry */
            $entry = BreachRegisterEntry::query()->create([
                'reference' => $reference,
                'discovered_at' => $discoveredAt,
                'occurred_at' => $occurredAt,
                'description' => $description,
                'severity' => $severity,
                'data_categories' => $dataCategories,
                'subjects_affected' => $subjectsAffected,
                'cause' => $cause,
                'status' => 'open',
            ]);

            $this->appendEvent(
                breachReference: $reference,
                action: 'registered',
                changes: [
                    'severity' => $severity,
                    'subjects_affected' => $subjectsAffected,
                    'data_categories' => $dataCategories,
                ],
                actor: $actor,
            );

            return $entry;
        });

        return $breach;
    }

    /**
     * Update fields on an existing breach row and log the diff. The
     * diff in the log contains BEFORE / AFTER pairs for each changed
     * field. Fields that were already at the requested value are
     * skipped silently.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(string $reference, array $changes, ?string $actor = null): BreachRegisterEntry
    {
        /** @var BreachRegisterEntry $result */
        $result = DB::transaction(function () use ($reference, $changes, $actor): BreachRegisterEntry {
            $breach = $this->locate($reference);

            $diff = [];

            foreach ($changes as $field => $newValue) {
                if (! in_array($field, $breach->getFillable(), true)) {
                    throw new \InvalidArgumentException("Field '{$field}' is not a writable column on the breach register.");
                }

                $oldValue = $breach->getAttribute($field);

                if ($oldValue == $newValue) { // intentional loose compare for Carbon equality
                    continue;
                }

                $diff[$field] = [
                    'from' => $this->scalarize($oldValue),
                    'to' => $this->scalarize($newValue),
                ];

                $breach->setAttribute($field, $newValue);
            }

            if ($diff === []) {
                return $breach;
            }

            $breach->save();

            $this->appendEvent(
                breachReference: $reference,
                action: 'updated',
                changes: $diff,
                actor: $actor,
            );

            return $breach;
        });

        return $result;
    }

    /**
     * Mark the supervisory authority (AP in NL) as notified. Optionally
     * attach the notification reference (e.g. AP case number).
     */
    public function reportToAuthority(
        string $reference,
        ?Carbon $reportedAt = null,
        ?string $notificationReference = null,
        ?string $actor = null,
    ): BreachRegisterEntry {
        /** @var BreachRegisterEntry $result */
        $result = DB::transaction(function () use ($reference, $reportedAt, $notificationReference, $actor): BreachRegisterEntry {
            $breach = $this->locate($reference);
            $reportedAt ??= Carbon::now();

            $breach->reported_to_authority_at = $reportedAt;
            $breach->save();

            $this->appendEvent(
                breachReference: $reference,
                action: 'reported_authority',
                changes: ['notification_reference' => $notificationReference],
                actor: $actor,
                occurredAt: $reportedAt,
            );

            return $breach;
        });

        return $result;
    }

    /**
     * Mark the affected subjects as notified (art. 34).
     */
    public function reportToSubjects(
        string $reference,
        ?Carbon $reportedAt = null,
        ?string $channel = null,
        ?string $actor = null,
    ): BreachRegisterEntry {
        /** @var BreachRegisterEntry $result */
        $result = DB::transaction(function () use ($reference, $reportedAt, $channel, $actor): BreachRegisterEntry {
            $breach = $this->locate($reference);
            $reportedAt ??= Carbon::now();

            $breach->reported_to_subjects_at = $reportedAt;
            $breach->save();

            $this->appendEvent(
                breachReference: $reference,
                action: 'reported_subjects',
                changes: ['channel' => $channel],
                actor: $actor,
                occurredAt: $reportedAt,
            );

            return $breach;
        });

        return $result;
    }

    /**
     * Move the breach into the `contained` status.
     */
    public function contain(string $reference, ?string $actor = null): BreachRegisterEntry
    {
        return $this->transitionStatus($reference, 'contained', 'contained', $actor);
    }

    /**
     * Move the breach into the `resolved` status.
     */
    public function resolve(string $reference, ?string $actor = null): BreachRegisterEntry
    {
        return $this->transitionStatus($reference, 'resolved', 'resolved', $actor);
    }

    /**
     * Locate a breach by reference or throw.
     */
    private function locate(string $reference): BreachRegisterEntry
    {
        $this->ensureNotEmpty('reference', $reference);

        /** @var BreachRegisterEntry|null $breach */
        $breach = BreachRegisterEntry::query()->where('reference', '=', $reference)->first();

        if ($breach === null) {
            throw new \InvalidArgumentException("No breach found with reference '{$reference}'.");
        }

        return $breach;
    }

    /**
     * Shared status-transition implementation for contain / resolve.
     */
    private function transitionStatus(
        string $reference,
        string $newStatus,
        string $action,
        ?string $actor,
    ): BreachRegisterEntry {
        /** @var BreachRegisterEntry $result */
        $result = DB::transaction(function () use ($reference, $newStatus, $action, $actor): BreachRegisterEntry {
            $breach = $this->locate($reference);

            if ($breach->status === $newStatus) {
                return $breach;
            }

            $previousStatus = $breach->status;
            $breach->status = $newStatus;
            $breach->save();

            $this->appendEvent(
                breachReference: $reference,
                action: $action,
                changes: ['from' => $previousStatus, 'to' => $newStatus],
                actor: $actor,
            );

            return $breach;
        });

        return $result;
    }

    /**
     * Append one event to `breach_event_log`, hashing it into the chain.
     *
     * @param  array<string, mixed>|null  $changes
     */
    private function appendEvent(
        string $breachReference,
        string $action,
        ?array $changes,
        ?string $actor,
        ?Carbon $occurredAt = null,
    ): BreachEventLogEntry {
        $occurredAt ??= Carbon::now();

        $previous = BreachEventLogEntry::query()->orderByDesc('id')->lockForUpdate()->first();
        $previousHash = $previous instanceof BreachEventLogEntry ? $previous->hash : '';

        $occurredAtString = $occurredAt->utc()->format('Y-m-d H:i:s');

        $changesString = $changes === null ? null : json_encode(
            $changes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($changes !== null && $changesString === false) {
            throw new \RuntimeException('Failed to serialize breach event changes as JSON.');
        }

        $payload = [
            'breach_reference' => $breachReference,
            'action' => $action,
            'changes' => $changesString,
            'actor' => $actor,
            'occurred_at' => $occurredAtString,
        ];

        $secret = config('data-retention.log_secret');
        $hash = HashChain::compute(
            $payload,
            $previousHash,
            is_string($secret) ? $secret : '',
        );

        /** @var BreachEventLogEntry $event */
        $event = BreachEventLogEntry::query()->create([
            'breach_reference' => $breachReference,
            'action' => $action,
            'changes' => $changes,
            'actor' => $actor,
            'occurred_at' => $occurredAt,
            'previous_hash' => $previousHash,
            'hash' => $hash,
        ]);

        return $event;
    }

    /**
     * Reduce a value to something JSON-safe for the event-log diff.
     */
    private function scalarize(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->utc()->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_scalar($value) || $value === null || is_array($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function ensureNotEmpty(string $field, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("Field '{$field}' must not be empty.");
        }
    }

    private function ensureSeverity(string $severity): void
    {
        if (! isset(self::ALLOWED_SEVERITIES[$severity])) {
            throw new \InvalidArgumentException(
                "Severity '{$severity}' is not valid. Allowed: low, medium, high, critical."
            );
        }
    }
}
