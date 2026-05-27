<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Ginkelsoft\DataRetention\Contracts\Exportable;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Support\ExportableConfig;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\SubjectExportDataset;
use Ginkelsoft\DataRetention\Support\SubjectHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collects all personal data the application holds about one subject
 * across every Exportable model, and returns it as an immutable
 * {@see SubjectExportDataset}.
 *
 * This action is strictly **read-only**: it never modifies, deletes,
 * or anonymizes anything. The exporters consume the dataset and
 * render it in their chosen format (JSON, Markdown, ...).
 *
 * For accountability, the action appends one row per matched model to
 * the existing `retention_log` (same hash chain), recording that the
 * access happened — without the exported content. The `model_id`
 * column of those rows holds the irreversible {@see SubjectHash} of
 * the subject, and `retention_field` is set to the sentinel value
 * `subject_access` so the rows are distinguishable from time-driven
 * retention rows at query time.
 */
final class CollectSubjectData
{
    /**
     * Collect every Exportable record linked to the given subject.
     *
     * Soft-deleted records are included when
     * `data-retention.include_soft_deleted` is true — the access
     * obligation covers data the application still holds even if
     * normally hidden by a global scope.
     *
     * @param  string  $subjectId  Identifier the application uses
     *                             to reference this subject across
     *                             models (typically a primary key
     *                             or ULID).
     * @param  bool  $logAccess  When true, append rows to
     *                           `retention_log` so the access is
     *                           itself accountable. Default true.
     */
    public function collect(string $subjectId, bool $logAccess = true): SubjectExportDataset
    {
        if ($subjectId === '') {
            throw new \InvalidArgumentException('Subject identifier must not be empty.');
        }

        $collectedAt = Carbon::now();
        $perModel = [];
        $totalRecords = 0;

        foreach ($this->resolveModels() as $modelClass) {
            $policy = ExportableConfig::for($modelClass);

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

            $rows = [];
            foreach ($records as $record) {
                $rows[] = $this->extractFields($record, $policy);
            }

            $perModel[$modelClass] = $rows;
            $totalRecords += count($rows);
        }

        $dataset = new SubjectExportDataset(
            subjectId: $subjectId,
            collectedAt: $collectedAt,
            perModel: $perModel,
            totalRecords: $totalRecords,
        );

        if ($logAccess && $perModel !== []) {
            $this->logAccess($subjectId, $perModel, $collectedAt);
        }

        return $dataset;
    }

    /**
     * Extract every configured field from the record, applying the
     * field's transform if present. Returns a `label => value` map.
     *
     * @return array<string, mixed>
     */
    private function extractFields(Model $record, ExportableConfig $policy): array
    {
        $row = [];

        foreach ($policy->fields as $field => $spec) {
            $raw = $record->getAttribute($field);
            $value = $spec['transform'] !== null
                ? ($spec['transform'])($raw, $field, $record)
                : $raw;

            $row[$spec['label']] = $value;
        }

        return $row;
    }

    /**
     * Append one row per matched model to `retention_log`, recording
     * that an access export happened. The log holds no field values —
     * only counts and metadata, so privacy by design is preserved.
     *
     * @param  array<class-string, list<array<string, mixed>>>  $perModel
     */
    private function logAccess(string $subjectId, array $perModel, Carbon $performedAt): void
    {
        $secret = config('data-retention.log_secret');
        $secret = is_string($secret) ? $secret : '';

        $subjectHash = SubjectHash::compute($subjectId, $secret);

        DB::transaction(function () use ($perModel, $subjectHash, $performedAt, $secret): void {
            foreach ($perModel as $modelClass => $rows) {
                $previous = RetentionLogEntry::query()
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $previousHash = $previous instanceof RetentionLogEntry ? $previous->hash : '';
                $performedAtString = $performedAt->utc()->format('Y-m-d H:i:s');

                $payload = [
                    'model_type' => $modelClass,
                    'model_id' => $subjectHash,
                    'action' => 'subject_access_exported',
                    'retention_period' => count($rows).' records',
                    'retention_field' => 'subject_access',
                    'expired_at' => $performedAtString,
                    'performed_at' => $performedAtString,
                ];

                $hash = HashChain::compute($payload, $previousHash, $secret);

                RetentionLogEntry::query()->create($payload + [
                    'previous_hash' => $previousHash,
                    'hash' => $hash,
                ]);
            }
        });
    }

    /**
     * Read the configured exportable model classes, filtering out
     * anything that is not actually a model implementing the contract.
     *
     * @return list<class-string<Model&Exportable>>
     */
    private function resolveModels(): array
    {
        $configured = config('data-retention.exportable.models', []);

        if (! is_array($configured)) {
            return [];
        }

        $valid = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Exportable::class)) {
                continue;
            }

            /** @var class-string<Model&Exportable> $class */
            $valid[] = $class;
        }

        return $valid;
    }
}
