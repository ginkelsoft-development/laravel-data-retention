<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Ginkelsoft\DataRetention\Support\BreachDeadlines;
use Illuminate\Console\Command;

/**
 * Reports breaches that are overdue or approaching the 72-hour
 * notification deadline under AVG art. 33(1).
 *
 *   php artisan retention:breach:deadlines
 *   php artisan retention:breach:deadlines --warning=48
 *
 * Suitable as a scheduled task: run hourly or daily, route the output
 * to whichever escalation channel your organisation uses (Slack, email,
 * PagerDuty).
 */
class BreachDeadlinesCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:breach:deadlines
        {--warning=24 : Hours of advance warning before the 72h deadline}';

    /** @var string */
    protected $description = 'Show breaches that are overdue or approaching the 72-hour notification deadline.';

    public function handle(): int
    {
        $warningOption = $this->option('warning');
        $warningHours = is_numeric($warningOption) ? (int) $warningOption : 24;

        if ($warningHours < 1) {
            $this->error('--warning must be a positive integer.');

            return self::FAILURE;
        }

        $deadlines = new BreachDeadlines($warningHours);

        $overdue = $deadlines->overdue();
        $approaching = $deadlines->approaching();

        if ($overdue->isEmpty() && $approaching->isEmpty()) {
            $this->info('No breaches overdue or approaching the 72-hour deadline.');

            return self::SUCCESS;
        }

        if ($overdue->isNotEmpty()) {
            $this->error(sprintf('OVERDUE (deadline already passed): %d', $overdue->count()));
            $this->table(
                ['Reference', 'Severity', 'Discovered', 'Deadline was'],
                $overdue->map(function (BreachRegisterEntry $b): array {
                    return [
                        $b->reference,
                        $b->severity,
                        $b->discovered_at->utc()->toIso8601String(),
                        $b->authorityNotificationDeadline()->utc()->toIso8601String(),
                    ];
                })->toArray(),
            );
        }

        if ($approaching->isNotEmpty()) {
            $this->warn(sprintf('Approaching within %d hours: %d', $warningHours, $approaching->count()));
            $this->table(
                ['Reference', 'Severity', 'Discovered', 'Deadline'],
                $approaching->map(function (BreachRegisterEntry $b): array {
                    return [
                        $b->reference,
                        $b->severity,
                        $b->discovered_at->utc()->toIso8601String(),
                        $b->authorityNotificationDeadline()->utc()->toIso8601String(),
                    ];
                })->toArray(),
            );
        }

        // Non-zero exit code when there are overdue breaches; useful
        // when this command runs in a scheduled job that should alert.
        return $overdue->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
