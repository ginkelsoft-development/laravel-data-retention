<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Actions\ApplyRetention;
use Ginkelsoft\DataRetention\Actions\ResolveExpiredRecords;
use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Class RunRetentionCommand
 *
 * Iterates every model listed in `data-retention.models`, finds the
 * records whose retention period has expired, and applies the
 * configured action (delete or anonymize). Every action is recorded
 * in the `retention_log` table.
 *
 * Designed to be scheduled daily:
 *
 *   // app/Console/Kernel.php
 *   $schedule->command('retention:run')->dailyAt('02:00');
 *
 * The command is idempotent — re-running it the same day finds
 * nothing left to process and writes no extra log rows.
 *
 * Options:
 *   --model=Foo\Bar    Only process the given fully-qualified class.
 *   --chunk=500        Override the configured chunk size.
 *   --dry-run          Report what would happen, change nothing.
 */
class RunRetentionCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:run
        {--model= : Process only this fully-qualified model class}
        {--chunk= : Override the configured chunk size}
        {--dry-run : Show what would happen without writing any changes}';

    /** @var string */
    protected $description = 'Apply GDPR retention rules to every configured model and log the result.';

    public function handle(ResolveExpiredRecords $resolver, ApplyRetention $apply): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkOption = $this->option('chunk');
        $configuredChunk = config('data-retention.chunk_size', 500);

        $chunkSize = match (true) {
            is_numeric($chunkOption) => (int) $chunkOption,
            is_numeric($configuredChunk) => (int) $configuredChunk,
            default => 500,
        };

        if ($chunkSize < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        $models = $this->resolveModels();

        if ($models === []) {
            $this->warn('No models configured for retention. Register them in config/data-retention.php.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[dry-run] No changes will be written to the database.');
        }

        $totalProcessed = 0;

        foreach ($models as $modelClass) {
            $totalProcessed += $this->processModel($modelClass, $resolver, $apply, $chunkSize, $dryRun);
        }

        $this->info(sprintf(
            '%s %d record(s) across %d model(s).',
            $dryRun ? '[dry-run] Would process' : 'Processed',
            $totalProcessed,
            count($models),
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve which model classes the command should operate on.
     *
     * @return list<class-string<Model>>
     */
    private function resolveModels(): array
    {
        $override = $this->option('model');

        if (is_string($override) && $override !== '') {
            if (! class_exists($override) || ! is_subclass_of($override, Model::class)) {
                $this->error("Model class not found or not an Eloquent model: {$override}");

                return [];
            }

            /** @var class-string<Model> $override */
            return [$override];
        }

        $configured = config('data-retention.models', []);

        if (! is_array($configured)) {
            return [];
        }

        $valid = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                $this->warn('Skipping unknown model: '.(is_string($class) ? $class : gettype($class)));

                continue;
            }

            /** @var class-string<Model> $class */
            $valid[] = $class;
        }

        return $valid;
    }

    /**
     * Process a single model class. Returns the number of affected rows.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function processModel(
        string $modelClass,
        ResolveExpiredRecords $resolver,
        ApplyRetention $apply,
        int $chunkSize,
        bool $dryRun,
    ): int {
        $policy = RetentionConfig::for($modelClass);

        if ($policy === null) {
            $this->warn("Skipping {$modelClass}: no retention policy declared.");

            return 0;
        }

        $this->line(sprintf(
            ' - %s: %s after %s (from %s)',
            $modelClass,
            $policy->action,
            $policy->period,
            $policy->from,
        ));

        $count = 0;

        $resolver->query($modelClass)->chunkById(
            $chunkSize,
            function ($records) use ($apply, $policy, $dryRun, &$count): void {
                foreach ($records as $record) {
                    $apply->apply($record, $policy, $dryRun);
                    $count++;
                }
            }
        );

        return $count;
    }
}
