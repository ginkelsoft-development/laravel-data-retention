<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Illuminate\Support\Collection;

/**
 * Read-only helper that answers "is consent active for this subject
 * and purpose?" by querying the consent_log event stream.
 *
 * Active consent is defined as: the most recent event for the
 * (subject, purpose, version) combination has `action = granted`.
 * Absence of any event, or a `withdrawn` event being the latest, both
 * mean consent is not active.
 *
 * Version handling: by default the helper considers the latest event
 * regardless of version (so a grant on v2 supersedes a withdrawal on
 * v1, and vice versa). Pass an explicit `$version` argument to scope
 * the check to a specific consent text version.
 */
final class ConsentStatus
{
    /**
     * Is consent currently active for this (subject, purpose [, version])?
     */
    public function isGranted(string $subjectId, string $purpose, ?string $version = null): bool
    {
        $latest = $this->latest($subjectId, $purpose, $version);

        return $latest instanceof ConsentEntry && $latest->action === 'granted';
    }

    /**
     * Return the most recent consent event for this (subject, purpose [, version]),
     * or null when no event exists.
     */
    public function latest(string $subjectId, string $purpose, ?string $version = null): ?ConsentEntry
    {
        $query = ConsentEntry::query()
            ->where('subject_id', '=', $subjectId)
            ->where('purpose', '=', $purpose);

        if ($version !== null) {
            $query->where('version', '=', $version);
        }

        /** @var ConsentEntry|null $entry */
        $entry = $query->orderByDesc('occurred_at')->orderByDesc('id')->first();

        return $entry;
    }

    /**
     * Return the full history for a subject. Optionally filter by
     * purpose. Results are ordered chronologically (oldest first).
     *
     * @return Collection<int, ConsentEntry>
     */
    public function history(string $subjectId, ?string $purpose = null): Collection
    {
        $query = ConsentEntry::query()->where('subject_id', '=', $subjectId);

        if ($purpose !== null) {
            $query->where('purpose', '=', $purpose);
        }

        /** @var Collection<int, ConsentEntry> $results */
        $results = $query->orderBy('occurred_at')->orderBy('id')->get();

        return $results;
    }

    /**
     * Return the active consents for this subject as a map of
     * `purpose => latest ConsentEntry` (with action = granted). Purposes
     * whose latest event is a withdrawal are excluded.
     *
     * Useful for "show me everything this subject currently consents to"
     * in an account dashboard.
     *
     * @return array<string, ConsentEntry>
     */
    public function activeFor(string $subjectId): array
    {
        $rows = ConsentEntry::query()
            ->where('subject_id', '=', $subjectId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, ConsentEntry> $latestPerPurpose */
        $latestPerPurpose = [];

        foreach ($rows as $row) {
            /** @var ConsentEntry $row */
            $latestPerPurpose[$row->purpose] = $row;
        }

        return array_filter(
            $latestPerPurpose,
            fn (ConsentEntry $entry): bool => $entry->action === 'granted',
        );
    }
}
