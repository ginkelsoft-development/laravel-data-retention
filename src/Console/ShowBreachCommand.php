<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Models\BreachEventLogEntry;
use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Illuminate\Console\Command;

/**
 * Prints the full state of one breach plus its event log.
 *
 *   php artisan retention:breach:show BREACH-2026-001
 */
class ShowBreachCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:breach:show {reference : Breach reference}';

    /** @var string */
    protected $description = 'Show one breach with its full event log.';

    public function handle(): int
    {
        $referenceArg = $this->argument('reference');
        $reference = is_string($referenceArg) ? $referenceArg : '';

        if ($reference === '') {
            $this->error('Reference must not be empty.');

            return self::FAILURE;
        }

        /** @var BreachRegisterEntry|null $breach */
        $breach = BreachRegisterEntry::query()->where('reference', '=', $reference)->first();

        if ($breach === null) {
            $this->error("No breach found with reference '{$reference}'.");

            return self::FAILURE;
        }

        $this->line("Breach: {$breach->reference}");
        $this->line('  Status:      '.$breach->status);
        $this->line('  Severity:    '.$breach->severity);
        $this->line('  Discovered:  '.$breach->discovered_at->utc()->toIso8601String());

        if ($breach->occurred_at !== null) {
            $this->line('  Occurred:    '.$breach->occurred_at->utc()->toIso8601String());
        }

        $this->line('  Subjects:    '.$breach->subjects_affected);
        $this->line('  Categories:  '.($breach->data_categories === null ? '-' : implode(', ', $breach->data_categories)));

        $authorityAt = $breach->reported_to_authority_at;
        $this->line('  Authority:   '.($authorityAt !== null
            ? $authorityAt->utc()->toIso8601String()
            : ($breach->isAuthorityNotificationOverdue() ? 'OVERDUE (72h passed)' : 'not yet (deadline '.$breach->authorityNotificationDeadline()->utc()->toIso8601String().')')));

        $this->line('  Subjects notified: '.($breach->reported_to_subjects_at === null
            ? 'not yet'
            : $breach->reported_to_subjects_at->utc()->toIso8601String()));

        $this->newLine();
        $this->line('Description:');
        $this->line('  '.$breach->description);

        if ($breach->cause !== null) {
            $this->newLine();
            $this->line('Cause:');
            $this->line('  '.$breach->cause);
        }

        if ($breach->mitigation !== null) {
            $this->newLine();
            $this->line('Mitigation:');
            $this->line('  '.$breach->mitigation);
        }

        $this->newLine();
        $this->line('Event log:');

        $events = BreachEventLogEntry::query()
            ->where('breach_reference', '=', $reference)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            $this->line('  (no events)');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            /** @var BreachEventLogEntry $event */
            $this->line(sprintf(
                '  %s | %s%s%s',
                $event->occurred_at->utc()->toIso8601String(),
                strtoupper($event->action),
                $event->actor !== null ? ' by '.$event->actor : '',
                $event->changes !== null ? ' '.json_encode($event->changes, JSON_UNESCAPED_SLASHES) : '',
            ));
        }

        return self::SUCCESS;
    }
}
