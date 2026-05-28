<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Actions\RecordConsent;
use Illuminate\Console\Command;

/**
 * Records a `granted` consent event for the given (subject, purpose).
 *
 *   php artisan retention:consent:grant alice newsletter
 *   php artisan retention:consent:grant alice newsletter --version=2 --source=web
 *   php artisan retention:consent:grant alice newsletter --metadata='{"ip":"203.0.113.5"}'
 *
 * Useful for ops, backfills, and tests. In production most grants come
 * from the application code itself by invoking
 * {@see RecordConsent::grant()} directly.
 */
class GrantConsentCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:consent:grant
        {subject : Subject identifier (e.g. user ID, ULID, email)}
        {purpose : What the subject is consenting to (e.g. "newsletter")}
        {--consent-version=1 : Version of the consent text or processing context}
        {--source= : Where the event came from (web, api, paper, ...)}
        {--metadata= : JSON-encoded metadata to attach to the event}';

    /** @var string */
    protected $description = 'Record a granted consent event for a subject.';

    public function handle(RecordConsent $action): int
    {
        $subjectArg = $this->argument('subject');
        $purposeArg = $this->argument('purpose');
        $subject = is_string($subjectArg) ? $subjectArg : '';
        $purpose = is_string($purposeArg) ? $purposeArg : '';

        $versionOption = $this->option('consent-version');
        $version = is_string($versionOption) && $versionOption !== '' ? $versionOption : '1';

        $sourceOption = $this->option('source');
        $source = is_string($sourceOption) && $sourceOption !== '' ? $sourceOption : null;

        $metadata = $this->parseMetadata($this->option('metadata'));

        if ($metadata === false) {
            return self::FAILURE;
        }

        $action->grant($subject, $purpose, $version, $source, $metadata);

        $this->info(sprintf(
            'Recorded GRANTED consent for subject %s, purpose %s (version %s).',
            $subject,
            $purpose,
            $version,
        ));

        return self::SUCCESS;
    }

    /**
     * Parse the --metadata option from a JSON string. Returns null when
     * absent, the decoded array on success, and false on parse error
     * (with an error printed to the console).
     *
     * @return array<string, mixed>|null|false
     */
    private function parseMetadata(mixed $raw): array|null|false
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Invalid JSON in --metadata: '.$e->getMessage());

            return false;
        }

        if (! is_array($decoded)) {
            $this->error('--metadata must decode to a JSON object.');

            return false;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
