<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Illuminate\Console\Command;

/**
 * Lists breaches in the register.
 *
 *   php artisan retention:breach:list
 *   php artisan retention:breach:list --status=open
 *
 * Use `retention:breach:show {reference}` for the full details + event
 * log of one specific breach.
 */
class ListBreachesCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:breach:list
        {--status= : Filter by status (open, contained, resolved)}';

    /** @var string */
    protected $description = 'List breaches in the register, optionally filtered by status.';

    public function handle(): int
    {
        $query = BreachRegisterEntry::query();

        $statusOption = $this->option('status');
        if (is_string($statusOption) && $statusOption !== '') {
            $query->where('status', '=', $statusOption);
        }

        $breaches = $query->orderByDesc('discovered_at')->get();

        if ($breaches->isEmpty()) {
            $this->info('No breaches found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($breaches as $breach) {
            /** @var BreachRegisterEntry $breach */
            $rows[] = [
                $breach->reference,
                $breach->severity,
                $breach->status,
                $breach->discovered_at->utc()->format('Y-m-d H:i'),
                $breach->isReportedToAuthority() ? 'yes' : ($breach->isAuthorityNotificationOverdue() ? 'OVERDUE' : 'no'),
                (string) $breach->subjects_affected,
            ];
        }

        $this->table(
            ['Reference', 'Severity', 'Status', 'Discovered', 'Authority notified', 'Subjects'],
            $rows,
        );

        return self::SUCCESS;
    }
}
