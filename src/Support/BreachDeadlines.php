<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Helpers for the 72-hour notification deadline of AVG art. 33(1).
 *
 * The supervisory authority must be notified within 72 hours of the
 * controller becoming aware of a personal-data breach. This support
 * class makes it easy to find breaches that are approaching or past
 * that deadline.
 *
 * "Approaching" is defined as: less than `$warningWindowHours` left
 * before the deadline (default 24 hours). Adjust this number to your
 * organisation's internal escalation policy.
 */
final class BreachDeadlines
{
    public function __construct(
        private readonly int $warningWindowHours = 24,
    ) {}

    /**
     * Breaches that have already passed the 72-hour deadline without
     * the supervisory authority being notified.
     *
     * @return Collection<int, BreachRegisterEntry>
     */
    public function overdue(?Carbon $asOf = null): Collection
    {
        $instant = $asOf ?? Carbon::now();
        $cutoff = $instant->copy()->subHours(72);

        /** @var Collection<int, BreachRegisterEntry> $breaches */
        $breaches = BreachRegisterEntry::query()
            ->whereNull('reported_to_authority_at')
            ->where('discovered_at', '<=', $cutoff)
            ->orderBy('discovered_at')
            ->get();

        return $breaches;
    }

    /**
     * Breaches whose 72-hour deadline lies within the warning window
     * (default: in the next 24 hours), but has not yet passed.
     *
     * @return Collection<int, BreachRegisterEntry>
     */
    public function approaching(?Carbon $asOf = null): Collection
    {
        $instant = $asOf ?? Carbon::now();

        // Approaching means: deadline lies in (now, now + warningWindowHours].
        // deadline = discovered_at + 72h.
        // So: discovered_at + 72h > now  AND  discovered_at + 72h <= now + warningWindow.
        // Rearranged: discovered_at > now - 72h  AND  discovered_at <= now + warningWindow - 72h.
        $lowerBoundDiscoveredAt = $instant->copy()->subHours(72);
        $upperBoundDiscoveredAt = $instant->copy()->addHours($this->warningWindowHours)->subHours(72);

        /** @var Collection<int, BreachRegisterEntry> $breaches */
        $breaches = BreachRegisterEntry::query()
            ->whereNull('reported_to_authority_at')
            ->where('discovered_at', '>', $lowerBoundDiscoveredAt)
            ->where('discovered_at', '<=', $upperBoundDiscoveredAt)
            ->orderBy('discovered_at')
            ->get();

        return $breaches;
    }
}
