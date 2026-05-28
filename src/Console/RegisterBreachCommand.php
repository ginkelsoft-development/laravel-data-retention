<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Actions\BreachRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Records a new personal-data breach in the register.
 *
 *   php artisan retention:breach:register BREACH-2026-001 \
 *       --description="Misdirected export of 42 client emails" \
 *       --severity=high \
 *       --subjects=42 \
 *       --categories=email,name \
 *       --discovered="2026-05-27 09:15"
 *
 * The 72-hour deadline for notifying the supervisory authority (art. 33
 * AVG) runs from `--discovered`. Defaults to now() when omitted.
 */
class RegisterBreachCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:breach:register
        {reference : Human-readable breach reference (e.g. BREACH-2026-001)}
        {--description= : Short factual description of what happened}
        {--severity=medium : low | medium | high | critical}
        {--discovered= : When the controller became aware (Y-m-d H:i, defaults to now)}
        {--occurred= : When the breach actually occurred, if known (Y-m-d H:i)}
        {--subjects=0 : Estimated number of affected subjects}
        {--categories= : Comma-separated data categories (e.g. "name,email,health")}
        {--cause= : Root cause if already known}
        {--actor= : Who is recording this entry}';

    /** @var string */
    protected $description = 'Record a new personal-data breach in the AVG art. 33 register.';

    public function handle(BreachRegistry $registry): int
    {
        $reference = $this->stringArg('reference');
        if ($reference === '') {
            $this->error('Reference must not be empty.');

            return self::FAILURE;
        }

        $description = $this->stringOption('description');
        if ($description === '') {
            $this->error('--description is required.');

            return self::FAILURE;
        }

        $severity = $this->stringOption('severity', 'medium');
        $discoveredRaw = $this->stringOption('discovered');
        $occurredRaw = $this->stringOption('occurred');
        $categoriesRaw = $this->stringOption('categories');
        $cause = $this->stringOption('cause');
        $actor = $this->stringOption('actor');

        try {
            $discoveredAt = $discoveredRaw === '' ? Carbon::now() : Carbon::parse($discoveredRaw);
            $occurredAt = $occurredRaw === '' ? null : Carbon::parse($occurredRaw);
        } catch (\Throwable $e) {
            $this->error('Could not parse a date option: '.$e->getMessage());

            return self::FAILURE;
        }

        $categories = $categoriesRaw === ''
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $categoriesRaw)), fn ($v) => $v !== ''));

        $subjects = (int) $this->stringOption('subjects', '0');

        try {
            $breach = $registry->register(
                reference: $reference,
                discoveredAt: $discoveredAt,
                description: $description,
                severity: $severity,
                occurredAt: $occurredAt,
                dataCategories: $categories,
                subjectsAffected: $subjects,
                cause: $cause === '' ? null : $cause,
                actor: $actor === '' ? null : $actor,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Registered breach %s. Authority notification deadline: %s.',
            $breach->reference,
            $breach->authorityNotificationDeadline()->utc()->toIso8601String(),
        ));

        return self::SUCCESS;
    }

    private function stringArg(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
