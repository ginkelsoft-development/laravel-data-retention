<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Ginkelsoft\DataRetention\Support\ConsentStatus;
use Illuminate\Console\Command;

/**
 * Prints the current consent state and event history for a subject.
 *
 *   php artisan retention:consent:status alice
 *
 * Prints two sections:
 *  - The active consents (latest event per purpose, action = granted).
 *  - The full chronological history of every event for the subject.
 */
class ShowConsentStatusCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:consent:status {subject : Subject identifier}';

    /** @var string */
    protected $description = 'Show the current consent state and event history for a subject.';

    public function handle(ConsentStatus $status): int
    {
        $subjectArg = $this->argument('subject');
        $subject = is_string($subjectArg) ? $subjectArg : '';

        if ($subject === '') {
            $this->error('Subject identifier must not be empty.');

            return self::FAILURE;
        }

        $active = $status->activeFor($subject);
        $history = $status->history($subject);

        $this->line(sprintf('Consent state for subject: %s', $subject));
        $this->newLine();
        $this->line('Active consents:');

        if ($active === []) {
            $this->line(' (none)');
        } else {
            foreach ($active as $purpose => $entry) {
                $this->line(sprintf(
                    ' - %s (version %s) granted at %s%s',
                    $purpose,
                    $entry->version,
                    $entry->occurred_at->utc()->toIso8601String(),
                    $entry->source !== null ? " via {$entry->source}" : '',
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf('Full history (%d event(s)):', $history->count()));

        if ($history->isEmpty()) {
            $this->line(' (none)');

            return self::SUCCESS;
        }

        foreach ($history as $entry) {
            /** @var ConsentEntry $entry */
            $this->line(sprintf(
                ' - %s | %s | %s (v%s) | source=%s',
                $entry->occurred_at->utc()->toIso8601String(),
                strtoupper($entry->action),
                $entry->purpose,
                $entry->version,
                $entry->source ?? '-',
            ));
        }

        return self::SUCCESS;
    }
}
