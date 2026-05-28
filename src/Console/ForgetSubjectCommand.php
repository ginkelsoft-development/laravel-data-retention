<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Actions\ForgetSubject;
use Illuminate\Console\Command;

/**
 * Class ForgetSubjectCommand
 *
 * Executes GDPR art. 17 ("right to be forgotten") for a single subject
 * across every model registered in `data-retention.forgettable.models`.
 *
 *   php artisan retention:forget 01HXYZ
 *   php artisan retention:forget user@example.com --dry-run
 *
 * The subject identifier is whatever string identifies the person
 * across your models; it must match the values in each model's
 * configured `column` (default `user_id`).
 *
 * The command is idempotent: running it twice for the same subject
 * finds no records the second time and writes no additional log
 * entries.
 */
class ForgetSubjectCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:forget
        {subject : Subject identifier (e.g. user ID, ULID, email)}
        {--dry-run : Show what would happen without writing any changes}';

    /** @var string */
    protected $description = 'Forget a subject across every model registered under data-retention.forgettable.models.';

    public function handle(ForgetSubject $action): int
    {
        $subjectArg = $this->argument('subject');
        $subject = is_string($subjectArg) ? $subjectArg : '';

        if ($subject === '') {
            $this->error('Subject identifier must not be empty.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[dry-run] No changes will be written to the database.');
        }

        $affected = $action->forget($subject, dryRun: $dryRun);

        if ($affected === []) {
            $this->info('No records found for this subject in any registered model.');

            return self::SUCCESS;
        }

        $totalRecords = 0;

        foreach ($affected as $modelClass => $keys) {
            $count = count($keys);
            $totalRecords += $count;
            $this->line(sprintf(' - %s: %d record(s)', $modelClass, $count));
        }

        $this->info(sprintf(
            '%s %d record(s) across %d model(s).',
            $dryRun ? '[dry-run] Would process' : 'Processed',
            $totalRecords,
            count($affected),
        ));

        return self::SUCCESS;
    }
}
