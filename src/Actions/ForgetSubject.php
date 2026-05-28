<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Ginkelsoft\DataRetention\Contracts\Forgettable;
use Ginkelsoft\DataRetention\Models\ForgetLogEntry;
use Ginkelsoft\DataRetention\Support\ForgettableConfig;
use Ginkelsoft\DataRetention\Support\SubjectHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Orchestrates GDPR art. 17 ("right to be forgotten") for one subject
 * across every model registered in `data-retention.forgettable.models`.
 *
 * For each model:
 *  - Resolves the {@see ForgettableConfig} from attribute / property.
 *  - Builds a query via the model's `forSubject` scope (default
 *    `WHERE column = :subject`; models can override the scope).
 *  - Includes soft-deleted rows where applicable.
 *  - Hands every matching record to {@see ApplyForget}.
 *
 * Idempotent: a second invocation for the same subject finds no rows
 * (the first run already deleted or anonymized them) and writes no
 * additional log entries.
 */
final class ForgetSubject
{
    public function __construct(
        private readonly ApplyForget $apply = new ApplyForget,
    ) {}

    /**
     * Forget a subject across all configured models.
     *
     * @param  string  $subjectId  Application-defined subject identifier.
     *                             Must be the same string used as the
     *                             value of each model's configured
     *                             `column`. Typically a primary key,
     *                             ULID, or UUID.
     * @param  Carbon|null  $requestedAt  When the subject filed the
     *                                    request, if known. Defaults
     *                                    to now() if null.
     * @return array<class-string<Model>, list<int|string>>
     *                                                      Map of model class to the list of primary keys affected.
     *                                                      In dry-run mode the keys are still returned so the caller
     *                                                      can show what would happen.
     */
    public function forget(string $subjectId, ?Carbon $requestedAt = null, bool $dryRun = false): array
    {
        if ($subjectId === '') {
            throw new \InvalidArgumentException('Subject identifier must not be empty.');
        }

        $secret = config('data-retention.log_secret');
        $subjectHash = SubjectHash::compute($subjectId, is_string($secret) ? $secret : '');

        $models = $this->resolveModels();
        $affected = [];

        foreach ($models as $modelClass) {
            $policy = ForgettableConfig::for($modelClass);

            if ($policy === null) {
                continue;
            }

            $query = $modelClass::forSubjectQuery($subjectId);

            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)
                && config('data-retention.include_soft_deleted', true)) {
                /** @var Builder<Model> $query */
                $query = $query->withTrashed(); // @phpstan-ignore-line method.notFound
            }

            $records = $query->get();

            if ($records->isEmpty()) {
                continue;
            }

            $keys = [];

            foreach ($records as $record) {
                /** @var int|string $key */
                $key = $record->getKey();

                // Idempotency: if this (subject, model, key) has already
                // been logged once, do not process it again. Records
                // that were anonymized in a previous run remain linked
                // to their original column value (e.g. user_id is still
                // there), so without this guard we would re-anonymize
                // and write duplicate audit rows.
                if (! $dryRun && $this->alreadyLogged($subjectHash, $modelClass, (string) $key)) {
                    continue;
                }

                $this->apply->apply($record, $policy, $subjectHash, $requestedAt, $dryRun);
                $keys[] = $key;
            }

            if ($keys !== []) {
                $affected[$modelClass] = $keys;
            }
        }

        return $affected;
    }

    /**
     * Has this (subject, model, key) combination already been recorded?
     */
    private function alreadyLogged(string $subjectHash, string $modelClass, string $modelId): bool
    {
        return ForgetLogEntry::query()
            ->where('subject_hash', $subjectHash)
            ->where('model_type', $modelClass)
            ->where('model_id', $modelId)
            ->exists();
    }

    /**
     * Read the configured forgettable model classes, filtering out
     * unknown or non-Eloquent classes defensively. Models that do not
     * implement the {@see Forgettable} contract are skipped because the
     * orchestrator relies on `forSubjectQuery()` being callable.
     *
     * @return list<class-string<Model&Forgettable>>
     */
    private function resolveModels(): array
    {
        $configured = config('data-retention.forgettable.models', []);

        if (! is_array($configured)) {
            return [];
        }

        $valid = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Forgettable::class)) {
                continue;
            }

            /** @var class-string<Model&Forgettable> $class */
            $valid[] = $class;
        }

        return $valid;
    }
}
