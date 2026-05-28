<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Console;

use Ginkelsoft\DataRetention\Actions\RecordConsent;
use Illuminate\Console\Command;

/**
 * Records a `withdrawn` consent event for the given (subject, purpose).
 *
 *   php artisan retention:consent:withdraw alice newsletter
 *   php artisan retention:consent:withdraw alice newsletter --version=2 --source=email
 */
class WithdrawConsentCommand extends Command
{
    /** @var string */
    protected $signature = 'retention:consent:withdraw
        {subject : Subject identifier}
        {purpose : What the subject is withdrawing consent for}
        {--consent-version=1 : Version of the consent text or processing context}
        {--source= : Where the event came from (web, api, email, ...)}
        {--metadata= : JSON-encoded metadata to attach to the event}';

    /** @var string */
    protected $description = 'Record a withdrawn consent event for a subject.';

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

        $metadataRaw = $this->option('metadata');
        $metadata = null;

        if (is_string($metadataRaw) && $metadataRaw !== '') {
            try {
                $decoded = json_decode($metadataRaw, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->error('Invalid JSON in --metadata: '.$e->getMessage());

                return self::FAILURE;
            }

            if (! is_array($decoded)) {
                $this->error('--metadata must decode to a JSON object.');

                return self::FAILURE;
            }

            /** @var array<string, mixed> $decoded */
            $metadata = $decoded;
        }

        $action->withdraw($subject, $purpose, $version, $source, $metadata);

        $this->info(sprintf(
            'Recorded WITHDRAWN consent for subject %s, purpose %s (version %s).',
            $subject,
            $purpose,
            $version,
        ));

        return self::SUCCESS;
    }
}
